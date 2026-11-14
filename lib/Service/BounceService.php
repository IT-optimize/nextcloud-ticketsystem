<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\MessageMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Mail\ParsedMail;
use OCA\TicketSystem\Mail\ThreadIds;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Bounces of Ticket system's own emails. The delivery report quotes the Message-ID
 * of the email that bounced, so the right reply is marked "not delivered"
 * (agents see it and can send it again) and the agent who wrote it is told.
 * A bounce for a CC address or for an automatic email (the confirmation)
 * becomes a line in the ticket's history instead.
 *
 * Only permanent failures count; "delayed" reports mean the server keeps trying.
 */
class BounceService {
	public function __construct(
		private MessageMapper $messageMapper,
		private TicketService $tickets,
		private ThreadIds $threadIds,
		private INotificationManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	public function record(ParsedMail $mail): void {
		$bounce = $mail->bounce;
		$messageId = $bounce['messageId'] ?? null;
		if ($bounce === null || $bounce['action'] !== 'failed' || $messageId === null) {
			return;
		}
		$ticketId = $this->threadIds->ticketIdFrom([$messageId]);
		$message = $this->messageMapper->findByMailId($messageId);
		if ($ticketId === null && $message === null) {
			// Not one of ours (or the ID was changed on the way): nothing to mark.
			return;
		}
		try {
			$ticket = $this->tickets->get($message?->getTicketId() ?? (int)$ticketId);
		} catch (NotFoundException) {
			return;
		}
		$recipient = $bounce['recipient'];
		$reason = $bounce['reason'] ?? $bounce['status'] ?? '';

		$isCustomer = $recipient === null || $recipient === $ticket->getCustomerEmail();
		if ($message !== null && $message->getAuthorType() === Message::AUTHOR_AGENT && $isCustomer) {
			$message->setDeliveryStatus(Message::DELIVERY_FAILED);
			$message->setDeliveryError(mb_substr('The receiving mail server returned the email' . ($recipient !== null ? ' for ' . $recipient : '') . ': ' . $reason, 0, 1000));
			$this->messageMapper->update($message);
			$this->tickets->touch($ticket->getId());
			foreach (array_unique(array_filter([$message->getAuthorUid(), $ticket->getAssigneeUid()])) as $uid) {
				$this->notify($uid, $ticket->getId(), $reason);
			}
		} else {
			$this->tickets->addHistoryEntry($ticket, null, [
				'type' => 'bounced',
				'recipient' => $recipient,
				'reason' => $reason,
			], 'An email to ' . ($recipient ?? 'the customer') . ' could not be delivered: ' . $reason);
			$this->tickets->touch($ticket->getId());
		}
		$this->logger->info('Ticket system recorded a bounce on ticket ' . $ticket->getId(), ['status' => $bounce['status']]);
	}

	private function notify(string $uid, int $ticketId, string $reason): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($uid)
				->setDateTime($this->time->getDateTime())
				->setObject('ticket', (string)$ticketId)
				->setSubject('delivery_failed', ['ticketId' => $ticketId, 'reason' => mb_substr($reason, 0, 200)]);
			$this->notifications->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Ticket system could not notify about a bounce', ['exception' => $e]);
		}
	}
}
