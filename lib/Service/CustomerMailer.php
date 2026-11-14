<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Attachment;
use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\MessageMapper;
use OCA\TicketSystem\Db\Team;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Db\TicketMapper;
use OCA\TicketSystem\Mail\CustomerEmail;
use OCA\TicketSystem\Mail\Names;
use OCA\TicketSystem\Mail\ThreadIds;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Util;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;

/**
 * Every email that goes to a customer: agent replies and the automatic
 * "we received your request" confirmation.
 *
 * Replies record their delivery result on the message (sent / failed /
 * skipped) so agents see failures in the ticket and can retry.
 */
class CustomerMailer {
	/** At most this many confirmations per sender address within ACK_WINDOW seconds. */
	private const ACK_LIMIT = 3;
	private const ACK_WINDOW = 3600;

	public function __construct(
		private IMailer $mailer,
		private SettingsService $settings,
		private MessageMapper $messageMapper,
		private TicketMapper $ticketMapper,
		private ITimeFactory $time,
		private IURLGenerator $url,
		private IFactory $l10nFactory,
		private IUserManager $userManager,
		private TeamService $teams,
		private ThreadIds $threadIds,
		private AttachmentService $attachments,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Email a public agent reply to the customer and store the outcome on the message.
	 * Never throws: a failed email must not lose the reply itself.
	 */
	public function sendReply(Ticket $ticket, Message $message): Message {
		if (!$message->isPublic() || $message->getAuthorType() !== Message::AUTHOR_AGENT || $ticket->getCustomerEmail() === null) {
			return $message;
		}
		if (!$this->settings->sendCustomerEmails()) {
			return $this->record($message, Message::DELIVERY_SKIPPED, null);
		}
		$l = $this->l10n($ticket);
		$paragraphs = array_values(array_filter(preg_split('/\R{2,}/', $message->getBody()) ?: [], fn (string $p) => trim($p) !== ''));
		$signature = trim((string)$this->brand($ticket)->getSignature());
		if ($signature !== '') {
			$paragraphs = array_merge($paragraphs, preg_split('/\R{2,}/', $signature) ?: []);
		}
		// "Alice Agent, Ticket system Support": customers see a person answering.
		$agent = $message->getAuthorUid() !== null ? $this->userManager->getDisplayName($message->getAuthorUid()) : null;
		$fromName = $agent !== null && $agent !== '' ? $agent . ', ' . $this->senderName($ticket) : null;
		$files = $this->attachments->forMessages([$message->getId()])[$message->getId()] ?? [];
		$error = $this->send($ticket, null, $paragraphs, $l, null, ['fromName' => $fromName, 'cc' => true, 'files' => $files], $mailId);
		if ($error === null && $mailId !== null) {
			$message->setMailId($mailId);
		}
		return $this->record($message, $error === null ? Message::DELIVERY_SENT : Message::DELIVERY_FAILED, $error);
	}

	/**
	 * Automatic confirmation for a ticket that arrived by email.
	 *
	 * Marked Auto-Submitted, so well-behaved auto-responders don't answer it,
	 * and capped per sender, so a misbehaving one can't start a mail loop.
	 */
	public function sendAcknowledgment(Ticket $ticket): void {
		$email = $ticket->getCustomerEmail();
		if ($email === null || !$this->settings->sendCustomerEmails() || !$this->settings->sendAcknowledgment()) {
			return;
		}
		$recent = $this->ticketMapper->countEmailTicketsFrom($email, $this->time->getTime() - self::ACK_WINDOW);
		if ($recent > self::ACK_LIMIT) {
			$this->logger->warning(sprintf(
				'Ticket system did not confirm ticket %d: %s opened %d tickets by email within an hour (possible mail loop)',
				$ticket->getId(), $email, $recent,
			));
			return;
		}
		$l = $this->l10n($ticket);
		$name = Names::first($ticket->getCustomerName());
		$error = $this->send($ticket, $ticket->getSubject(), [
			$name !== null ? $l->t('Hi %s,', [$name]) : $l->t('Hi,'),
			$l->t('Thank you for contacting us. We have received your request and will get back to you as soon as possible.'),
			$l->t('Your reference is %s. To add more information, just reply to this email.', [$ticket->getReference()]),
		], $l, AutoSubmitted::VALUE_AUTO_REPLIED);
		if ($error !== null) {
			$this->logger->warning('Ticket system could not send the confirmation for ticket ' . $ticket->getId() . ': ' . $error);
		}
	}

	public function portalUrl(Ticket $ticket): ?string {
		if (!$this->settings->portalEnabled()) {
			return null;
		}
		return $this->url->linkToRouteAbsolute('ticketsystem.portal.show', ['token' => $ticket->getAccessToken()]);
	}

	/**
	 * @param string[] $paragraphs plain text
	 * @param string|null $autoSubmitted Auto-Submitted header for machine-sent mail, null for a person's reply
	 * @param string|null $heading big title above the text, null for none (a person's reply reads like a letter)
	 * @param array{buttons?: list<array{0: string, 1: string}>, after?: string, portalButton?: bool, fromName?: ?string, cc?: bool, files?: Attachment[]} $extra
	 *        cc: copy the people on the ticket's CC list. The automatic confirmation goes to the sender only.
	 * @param-out string|null $mailId the Message-ID it was sent with, when Ticket system could choose it
	 * @return string|null error message, or null when the mail server accepted it
	 */
	private function send(Ticket $ticket, ?string $heading, array $paragraphs, IL10N $l, ?string $autoSubmitted = null, array $extra = [], ?string &$mailId = null): ?string {
		$subject = sprintf('[%s] %s', $ticket->getReference(), $ticket->getSubject());
		$brand = $this->brand($ticket);
		$template = new CustomerEmail($this->senderName($ticket), $brand->getColor(), $l->getLanguageCode());
		$template->setSubject($subject);
		// Mail programs show this in the inbox list instead of the marker.
		$preview = trim(preg_replace('/\s+/u', ' ', implode(' ', $paragraphs)) ?? '');
		$template->setPreheader(mb_strlen($preview) > 150 ? mb_substr($preview, 0, 149) . '…' : $preview);
		// Everything below this line in the customer's answer is cut off on import.
		$template->setMarker($l->t('##- Please type your reply above this line -##'));
		if ($heading !== null) {
			$template->addHeading(htmlspecialchars($heading), $heading);
		}
		foreach ($paragraphs as $paragraph) {
			$template->addBodyText(nl2br(htmlspecialchars($paragraph), false), $paragraph);
		}
		$buttons = $extra['buttons'] ?? [];
		if (count($buttons) === 2) {
			$template->addBodyButtonGroup(
				htmlspecialchars($buttons[0][0]), $buttons[0][1],
				htmlspecialchars($buttons[1][0]), $buttons[1][1],
				$buttons[0][0], $buttons[1][0],
			);
		}
		if (isset($extra['after'])) {
			$template->addBodyText(htmlspecialchars($extra['after']), $extra['after']);
		}
		$portal = $this->portalUrl($ticket);
		if ($portal !== null && ($extra['portalButton'] ?? true)) {
			// A personal reply (no heading) keeps the portal link quiet.
			if ($heading === null) {
				$template->addSecondaryButton(htmlspecialchars($l->t('View your request')), $portal, $l->t('View your request online'));
			} else {
				$template->addBodyButton(htmlspecialchars($l->t('View your request')), $portal, $l->t('View your request online'));
			}
		}
		// Replaces Nextcloud's default "please do not reply" footer: here replies are wanted.
		$template->addFooter(htmlspecialchars($this->senderName($ticket) . ' · ' . $l->t('Reference %s', [$ticket->getReference()])));

		$mail = $this->mailer->createMessage();
		$mail->setTo([$ticket->getCustomerEmail() => $ticket->getCustomerName() ?? $ticket->getCustomerEmail()]);
		$cc = ($extra['cc'] ?? false) ? $ticket->getCcList() : [];
		if ($cc !== []) {
			$mail->setCc($cc);
		}
		$mail->setFrom([Util::getDefaultEmailAddress('noreply') => $extra['fromName'] ?? $this->senderName($ticket)]);
		$support = $this->brand($ticket)->getReplyTo() ?? $this->settings->getSupportAddress();
		if ($support !== '') {
			$mail->setReplyTo([$support => $this->senderName($ticket)]);
		}
		$mail->useTemplate($template);
		try {
			foreach ($extra['files'] ?? [] as $file) {
				$mail->attach($this->mailer->createAttachment($this->attachments->file($file)->getContent(), $file->getFilename(), $file->getMimeType()));
			}
		} catch (\Throwable $e) {
			$this->logger->error('Ticket system could not read an attachment for ticket ' . $ticket->getId(), ['exception' => $e]);
			return 'A file of this reply could not be read, so the email was not sent. Send it again, or remove the file and write a new reply.';
		}
		if ($autoSubmitted !== null) {
			$mail->setAutoSubmitted($autoSubmitted);
		}
		$mailId = $this->addThreadHeaders($mail, $ticket);

		try {
			$failed = $this->mailer->send($mail);
			if ($failed !== []) {
				// Nextcloud's mailer logs the transport error itself and only reports the recipients.
				return 'Nextcloud could not send the email to ' . implode(', ', $failed)
					. '. Check the email server in Administration settings → Basic settings, and the Nextcloud log.';
			}
			return null;
		} catch (\Throwable $e) {
			$this->logger->error('Ticket system could not email the customer of ticket ' . $ticket->getId(), ['exception' => $e]);
			return $this->readableError($e);
		}
	}

	/**
	 * Message-ID, In-Reply-To and References, so the customer's mail program
	 * shows the ticket as one conversation and replies find their way back
	 * (see ThreadIds).
	 *
	 * OCP\Mail\IMessage cannot set headers, so this uses the Symfony Email
	 * inside Nextcloud's message. Where that is missing the email goes out
	 * without them, and replies are matched by the "[#42]" subject tag as before.
	 *
	 * @return string|null the Message-ID, or null when it could not be set
	 */
	private function addThreadHeaders(IMessage $mail, Ticket $ticket): ?string {
		$email = method_exists($mail, 'getSymfonyEmail') ? $mail->getSymfonyEmail() : null;
		if (!$email instanceof Email) {
			return null;
		}
		$domain = ThreadIds::domainOf(Util::getDefaultEmailAddress('noreply'));
		$root = $this->threadIds->rootId($ticket->getId(), $domain);
		// Ids from other mail systems are only repeated when they are well-formed;
		// Symfony refuses the whole header otherwise.
		$customer = array_values(array_filter(
			$this->messageMapper->customerMailIds($ticket->getId()),
			fn (string $id) => preg_match('/^[^\s<>@"]{1,250}@[^\s<>@"]{1,250}$/', $id) === 1,
		));
		$last = $customer !== [] ? $customer[count($customer) - 1] : null;
		$id = $this->threadIds->newId($ticket->getId(), $domain);
		$headers = $email->getHeaders();
		try {
			foreach (['Message-ID', 'In-Reply-To', 'References'] as $name) {
				$headers->remove($name);
			}
			$headers->addIdHeader('Message-ID', $id);
			$headers->addIdHeader('In-Reply-To', $last ?? $root);
			$headers->addIdHeader('References', array_values(array_unique(array_filter([$customer[0] ?? null, $root, $last]))));
			return $id;
		} catch (\Throwable $e) {
			$this->logger->warning('Ticket system could not set threading headers for ticket ' . $ticket->getId(), ['exception' => $e]);
			foreach (['Message-ID', 'In-Reply-To', 'References'] as $name) {
				$headers->remove($name);
			}
			return null;
		}
	}

	/** The brand a ticket belongs to (the default brand for old tickets). */
	public function brand(Ticket $ticket): Team {
		return $this->teams->find($ticket->getTeamId()) ?? $this->teams->getDefault();
	}

	/** The name customers see: the Email tab's sender name, else the company name. */
	public function senderName(Ticket $ticket): string {
		return $this->teams->senderNameFor($this->brand($ticket));
	}

	private function record(Message $message, string $status, ?string $error): Message {
		$message->setDeliveryStatus($status);
		$message->setDeliveryError($error !== null ? mb_substr($error, 0, 1000) : null);
		return $this->messageMapper->update($message);
	}

	/** The useful part of a mailer exception, without stack noise. */
	private function readableError(\Throwable $e): string {
		$messages = [];
		for ($t = $e; $t !== null; $t = $t->getPrevious()) {
			$text = trim(preg_replace('/\s+/', ' ', $t->getMessage()) ?? '');
			if ($text !== '' && !in_array($text, $messages, true)) {
				$messages[] = $text;
			}
		}
		return $messages !== [] ? implode(' – ', $messages) : 'Unknown error while sending the email';
	}

	/** Customer emails speak the brand's language, else the server's default language. */
	private function l10n(Ticket $ticket): IL10N {
		$language = $this->brand($ticket)->getLanguage() ?? $this->settings->customerLanguage();
		return $this->l10nFactory->get(Application::APP_ID, $language);
	}
}
