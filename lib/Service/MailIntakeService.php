<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\MessageMapper;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Mail\ImapClient;
use OCA\TicketSystem\Mail\ImapException;
use OCA\TicketSystem\Mail\MimeParser;
use OCA\TicketSystem\Mail\ParsedMail;
use OCA\TicketSystem\Mail\ReplyCleaner;
use OCA\TicketSystem\Mail\ThreadIds;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Reads the support inbox over IMAP and turns unread emails into tickets or
 * into replies on existing tickets.
 *
 * Threading: an email joins an existing ticket when it references a message
 * already stored there or any email Ticket system sent about it (In-Reply-To /
 * References, see ThreadIds), or when its subject carries
 * the ticket tag "[#42]" and the sender is that ticket's customer or from the
 * same company domain. Anything else becomes a new ticket, so a guessed
 * ticket number cannot be used to post into someone else's ticket.
 *
 * Processed emails are marked as read (and moved, if a folder is configured).
 * Auto-replies, bounces and mailing lists are marked as read and ignored.
 */
class MailIntakeService {
	private const LOCK = 'ticketsystem/mail-intake';
	private const MAX_PER_RUN = 50;
	private const MAX_SIZE = 25 * 1024 * 1024;

	/** Free-mail domains: sharing one does not make two senders colleagues. */
	private const PUBLIC_DOMAINS = [
		'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.dk', 'hotmail.com', 'hotmail.dk', 'live.com', 'live.dk',
		'msn.com', 'yahoo.com', 'yahoo.dk', 'icloud.com', 'me.com', 'mac.com', 'aol.com', 'gmx.com', 'gmx.de', 'gmx.net',
		'web.de', 'mail.dk', 'jubii.dk', 'protonmail.com', 'proton.me', 'yandex.com', 'mail.com', 'zoho.com',
	];

	public function __construct(
		private SettingsService $settings,
		private TicketService $tickets,
		private MessageMapper $messageMapper,
		private MimeParser $parser,
		private ReplyCleaner $cleaner,
		private ThreadIds $threadIds,
		private TeamService $teams,
		private AttachmentService $attachments,
		private BounceService $bounces,
		private ILockingProvider $locking,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Connects with the given settings and reports what it finds, without
	 * importing anything. Used by the "Test connection" button.
	 *
	 * @return array{folders: string[], messages: int, unread: int}
	 * @throws ImapException
	 */
	public function testConnection(array $config): array {
		if ($config['host'] === '' || $config['user'] === '' || $config['password'] === '') {
			throw new ImapException('Fill in the mail server, user name and password');
		}
		$client = $this->connect($config);
		try {
			$folders = $client->listFolders();
			$messages = $client->select($config['folder']);
			$unread = count($client->searchUnseen());
			return ['folders' => $folders, 'messages' => $messages, 'unread' => $unread];
		} finally {
			$client->logout();
		}
	}

	/**
	 * One intake run over the main support inbox and every brand's own inbox.
	 * An inbox that can't be reached is recorded on its settings and skipped;
	 * the others are still read. Safe to call from cron and occ at the same
	 * time: a second concurrent run returns immediately.
	 *
	 * @param bool $force also read the main inbox while intake is switched off
	 * @return array{created: int, replies: int, ignored: int, failed: int, skippedRun: bool, errors: list<string>}
	 */
	public function run(bool $force = false): array {
		$result = ['created' => 0, 'replies' => 0, 'ignored' => 0, 'failed' => 0, 'skippedRun' => false, 'errors' => []];
		$main = $this->settings->getImapConfig();
		$inboxes = [];
		if ($this->settings->intakeEnabled() || ($force && $main['host'] !== '')) {
			$inboxes[] = ['team' => null, 'config' => $main];
		}
		array_push($inboxes, ...$this->teams->inboxes());
		if ($inboxes === []) {
			$result['skippedRun'] = true;
			return $result;
		}
		try {
			$this->locking->acquireLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			$result['skippedRun'] = true;
			return $result;
		}

		try {
			foreach ($inboxes as $inbox) {
				$team = $inbox['team'];
				$counts = ['created' => 0, 'replies' => 0, 'ignored' => 0, 'failed' => 0];
				$error = null;
				$position = $team === null ? $this->settings->getIntakePosition() : $team->getInboxStatusData();
				try {
					$position = $this->readInbox($inbox['config'], $team?->getId(), $counts, $position);
				} catch (ImapException $e) {
					$error = $e->getMessage();
					$this->logger->warning('Ticket system email intake (' . ($team?->getName() ?? 'main inbox') . '): ' . $error);
					$result['errors'][] = $team !== null ? $team->getName() . ': ' . $error : $error;
				}
				$position = ['lastUid' => $position['lastUid'], 'uidValidity' => $position['uidValidity']];
				$imported = $counts['created'] + $counts['replies'];
				if ($team === null) {
					$this->settings->recordIntakeRun($error, $imported);
					$this->settings->setIntakePosition($position);
				} else {
					$this->teams->recordInboxRun($team, $error, $imported, $position);
				}
				foreach ($counts as $key => $count) {
					$result[$key] += $count;
				}
			}
		} finally {
			$this->locking->releaseLock(self::LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
		return $result;
	}

	/**
	 * Imports the new mail of one inbox. New means: after the last UID read,
	 * whether someone already read it in a mail program or not. The first
	 * time (or when the server renumbered the folder, a new UIDVALIDITY) it
	 * starts from the unread mail, so old history isn't imported.
	 *
	 * @param int|null $teamId the brand new tickets from this inbox belong to; null for the default brand
	 * @param array{created: int, replies: int, ignored: int, failed: int} $counts
	 * @param array{lastUid: ?int, uidValidity: ?int} $position where the previous run stopped
	 * @return array{lastUid: ?int, uidValidity: ?int} where this run stopped
	 * @throws ImapException
	 */
	private function readInbox(array $config, ?int $teamId, array &$counts, array $position): array {
		$client = $this->connect($config);
		try {
			$client->select($config['folder']);
			$validity = $client->uidValidity();
			$known = $position['lastUid'] !== null && $validity !== null && $position['uidValidity'] === $validity;
			$uids = $known ? $client->searchAfter((int)$position['lastUid']) : $client->searchUnseen();
			$lastUid = $known ? (int)$position['lastUid'] : 0;
			foreach (array_slice($uids, 0, self::MAX_PER_RUN) as $uid) {
				$outcome = $this->processUid($client, $uid, $teamId);
				$counts[$outcome]++;
				$this->finish($client, $uid, $config, $outcome === 'failed');
				$lastUid = max($lastUid, $uid);
			}
			if (!$known) {
				if (count($uids) > self::MAX_PER_RUN) {
					// Unread backlog left: keep going by "unread" until it is done.
					return ['lastUid' => null, 'uidValidity' => $validity];
				}
				// From now on, everything after the newest mail in the folder counts.
				$all = $client->searchAfter(0);
				$lastUid = max($lastUid, $all === [] ? 0 : max($all));
			}
			return ['lastUid' => $lastUid ?: null, 'uidValidity' => $validity];
		} finally {
			$client->logout();
		}
	}

	/** @return 'created'|'replies'|'ignored'|'failed' */
	private function processUid(ImapClient $client, int $uid, ?int $teamId): string {
		try {
			$size = $client->fetchSize($uid) ?? 0;
			$tooLarge = $size > self::MAX_SIZE;
			$mail = $this->parser->parse($tooLarge ? $client->fetchHeader($uid) : $client->fetchRaw($uid));
			return $this->process($mail, $tooLarge ? $size : null, $teamId);
		} catch (ImapException $e) {
			throw $e; // connection trouble: stop this run, retry next time
		} catch (\Throwable $e) {
			$this->logger->error('Ticket system could not import email UID ' . $uid, ['exception' => $e]);
			return 'failed';
		}
	}

	/**
	 * Imports one parsed email.
	 * @param int|null $oversize size in bytes when only the header was downloaded
	 * @param int|null $teamId the brand whose inbox it came from (new tickets only; replies stay on their ticket)
	 * @return 'created'|'replies'|'ignored'
	 */
	public function process(ParsedMail $mail, ?int $oversize = null, ?int $teamId = null): string {
		$from = $mail->fromEmail;
		if ($mail->bounce !== null) {
			// A bounce of one of our emails: mark it there, never a ticket.
			$this->bounces->record($mail);
		}
		// For a Helpdesk request the sender is a Nextcloud's no-reply address, which may
		// be this server's own when both apps run here; what counts is who wrote it.
		if ($from === null || $mail->isAutomatic || $this->isOwnAddress(self::requester($mail)[0])) {
			$this->logger->info('Ticket system ignored email', ['from' => $from, 'subject' => $mail->subject, 'automatic' => $mail->isAutomatic]);
			return 'ignored';
		}
		$messageId = $mail->messageId ?? $this->syntheticMessageId($mail);
		if ($this->messageMapper->existsMailId($messageId)) {
			return 'ignored';
		}

		$body = $oversize !== null ? '' : $this->cleaner->bodyText($mail);
		$notes = [];
		if ($oversize !== null) {
			$notes[] = sprintf('[This email was too large to import (%s). Open it in the support inbox.]', $this->formatSize($oversize));
		}
		// A picture inside the text that this sender sent before is their signature logo.
		$attachments = array_filter($mail->attachments, fn (array $a) => !($a['inline'] && str_starts_with($a['contentType'], 'image/')
			&& $this->attachments->sentBefore($a['content'], $from)));
		$files = array_values(array_map(fn (array $a) => [
			'filename' => $a['filename'],
			'contentType' => $a['contentType'],
			'content' => $a['content'],
		], $attachments));
		$fullBody = trim($body . ($notes !== [] ? "\n\n" . implode("\n", $notes) : ''));
		if ($fullBody === '' && $files === []) {
			$fullBody = '[Empty email]';
		}

		$ticketId = $this->findTicket($mail, $from);
		if ($ticketId !== null) {
			$ticket = $this->tickets->get($ticketId);
			$author = self::replyAuthor($mail, $ticket->getCustomerEmail(), $ticket->getCcList());
			$message = $this->tickets->addCustomerMessage($ticketId, $author, $fullBody, $messageId, $files);
			$this->addCopiedPeople($message->getTicketId(), $author, $mail);
			return 'replies';
		}

		// A tag of a ticket this sender may not post to is dropped, so it can't confuse agents.
		$subject = trim(preg_replace('/\s*\[#\d+\]\s*/', ' ', $mail->subject) ?? $mail->subject);
		[$customerEmail, $customerName] = $this->requester($mail);
		$this->tickets->create(null, [
			'subject' => $subject !== '' ? $subject : '(no subject)',
			'body' => $fullBody,
			'customerEmail' => $customerEmail,
			'customerName' => $customerName,
			'teamId' => $teamId,
			'cc' => array_slice($this->otherRecipients($mail, [$from, $customerEmail]), 0, Ticket::MAX_CC),
			'channel' => 'email',
			'mailMessageId' => $messageId,
			'files' => $files,
		]);
		return 'created';
	}

	/**
	 * Who a new ticket is for. Requests sent by the Helpdesk app come from the
	 * customer's Nextcloud (its no-reply address) on behalf of the person who
	 * wrote them, named in Reply-To. Only new tickets use this: replies must
	 * still come from the ticket's customer (see findTicket()).
	 *
	 * @return array{0: string, 1: ?string} address and name
	 */
	public static function requester(ParsedMail $mail): array {
		$viaHelpdesk = $mail->isHelpdeskRequest();
		if ($viaHelpdesk && $mail->replyToEmail !== null && filter_var($mail->replyToEmail, FILTER_VALIDATE_EMAIL)) {
			return [strtolower($mail->replyToEmail), $mail->replyToName];
		}
		return [(string)$mail->fromEmail, $mail->fromName];
	}

	/**
	 * Who wrote a reply on an existing ticket. The email was matched to the
	 * ticket by its real sender or by the thread it refers to; Reply-To played
	 * no part in that, because anyone can write anything there. A Helpdesk
	 * message is then filed under the person in Reply-To, but only when that
	 * person is already on the ticket (its customer or copied).
	 *
	 * @param list<string> $cc
	 */
	public static function replyAuthor(ParsedMail $mail, ?string $customer, array $cc): string {
		$from = (string)$mail->fromEmail;
		if (!$mail->isHelpdeskRequest()) {
			return $from;
		}
		$requester = self::requester($mail)[0];
		return $requester === $customer || in_array($requester, $cc, true) ? $requester : $from;
	}

	private function findTicket(ParsedMail $mail, string $from): ?int {
		// A reply that references something already in a ticket belongs there:
		// only people who were on the thread know those Message-IDs.
		$byReference = $this->messageMapper->findTicketIdByMailIds($mail->references);
		if ($byReference !== null) {
			return $byReference;
		}
		// An answer to any email Ticket system sent, also the confirmation.
		$signed = $this->threadIds->ticketIdFrom($mail->references);
		if ($signed !== null) {
			try {
				return $this->tickets->get($signed)->getId();
			} catch (NotFoundException) {
			}
		}
		if (!preg_match('/\[#(\d{1,18})\]/', $mail->subject, $m)) {
			return null;
		}
		try {
			$ticket = $this->tickets->get((int)$m[1]);
		} catch (NotFoundException) {
			return null;
		}
		$customer = $ticket->getCustomerEmail();
		if ($customer === null) {
			return null;
		}
		if ($customer === $from || in_array($from, $ticket->getCcList(), true)) {
			return $ticket->getId();
		}
		$domain = $this->domain($from);
		if ($domain === $this->domain($customer) && !in_array($domain, self::PUBLIC_DOMAINS, true)) {
			return $ticket->getId();
		}
		return null;
	}

	private function finish(ImapClient $client, int $uid, array $config, bool $failed): void {
		// Failed imports stay in the inbox, read and flagged, so nobody loses them
		// and the same broken email is not retried forever.
		$client->addFlags($uid, $failed ? ['\\Seen', '\\Flagged'] : ['\\Seen']);
		if (!$failed && $config['processedFolder'] !== '') {
			$client->move($uid, $config['processedFolder']);
		}
	}

	/** @throws ImapException */
	private function connect(array $config): ImapClient {
		$client = new ImapClient($config['host'], (int)$config['port'], $config['security'], (bool)$config['verifyTls']);
		$client->connect();
		$client->login($config['user'], $config['password']);
		return $client;
	}

	/** Mail sent by this Nextcloud (or to itself) must never loop back in as a ticket. */
	private function isOwnAddress(string $email): bool {
		return in_array($email, $this->teams->ownAddresses(), true);
	}

	/**
	 * The people an email went to besides Ticket system and the given addresses.
	 *
	 * @param string[] $except
	 * @return string[]
	 */
	private function otherRecipients(ParsedMail $mail, array $except): array {
		return array_values(array_diff($mail->recipients, $this->teams->ownAddresses(), $except));
	}

	/**
	 * When the customer or someone already copied answers and copies more
	 * people, they are copied on the ticket from now on. Anyone else answering
	 * (a forwarded email, say) can't widen who receives the replies.
	 */
	private function addCopiedPeople(int $ticketId, string $from, ParsedMail $mail): void {
		try {
			$ticket = $this->tickets->get($ticketId);
			$cc = $ticket->getCcList();
			if ($from !== $ticket->getCustomerEmail() && !in_array($from, $cc, true)) {
				return;
			}
			$new = $this->otherRecipients($mail, array_merge($cc, [(string)$ticket->getCustomerEmail()]));
			if ($new === [] || count($cc) >= Ticket::MAX_CC) {
				return;
			}
			$this->tickets->update(null, $ticketId, ['cc' => array_slice(array_merge($cc, $new), 0, Ticket::MAX_CC)]);
		} catch (\Throwable $e) {
			$this->logger->warning('Ticket system could not add the copied people to ticket ' . $ticketId, ['exception' => $e]);
		}
	}

	private function syntheticMessageId(ParsedMail $mail): string {
		return sha1(($mail->fromEmail ?? '') . "\n" . ($mail->headers['date'] ?? '') . "\n" . $mail->subject . "\n" . $mail->textBody . $mail->htmlBody) . '@ticketsystem.invalid';
	}

	private function domain(string $email): string {
		return strtolower(substr(strrchr($email, '@') ?: '', 1));
	}

	private function formatSize(int $bytes): string {
		return Util::humanFileSize($bytes);
	}
}
