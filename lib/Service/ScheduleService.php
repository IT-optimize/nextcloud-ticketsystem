<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\ScheduledReply;
use OCA\TicketSystem\Db\ScheduledReplyMapper;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Replies to the customer that are sent later, e.g. at 7 in the morning
 * instead of at 2 at night. Until then nothing reaches the customer: the
 * reply is sent exactly like one written at that moment, with the chosen
 * status change afterwards.
 */
class ScheduleService {
	/** Earliest: a minute from now. Latest: a year ahead. */
	public const MIN_AHEAD = 60;
	public const MAX_AHEAD = 366 * 86400;

	public function __construct(
		private ScheduledReplyMapper $mapper,
		private TicketService $tickets,
		private SettingsService $settings,
		private TeamService $teams,
		private AttachmentService $attachments,
		private IGroupManager $groupManager,
		private IManager $notifications,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** @return list<array<string, mixed>> the waiting replies, each with its files */
	public function pending(int $ticketId): array {
		return array_map(fn (ScheduledReply $r) => $r->jsonSerialize() + [
			'attachments' => $this->attachments->forAgent($this->attachments->forScheduled($r->getId())),
		], $this->mapper->findPending($ticketId));
	}

	/** @return array<int, int> */
	public function nextFor(array $ticketIds): array {
		return $ticketIds === [] ? [] : $this->mapper->nextFor($ticketIds);
	}

	/**
	 * @param int[] $attachmentIds the agent's uploads; they wait with the reply
	 * @throws ValidationException
	 */
	public function schedule(string $uid, Ticket $ticket, string $body, int $sendAt, ?string $thenStatus, array $attachmentIds = []): ScheduledReply {
		if ($ticket->getCustomerEmail() === null) {
			throw new ValidationException('This ticket has no customer email address to reply to');
		}
		$files = $attachmentIds !== [] ? $this->attachments->claim($uid, $ticket->getId(), $attachmentIds) : [];
		$reply = new ScheduledReply();
		$reply->setTicketId($ticket->getId());
		$reply->setUid($uid);
		$reply->setState(ScheduledReply::SCHEDULED);
		$this->apply($reply, ['body' => $body, 'sendAt' => $sendAt, 'thenStatus' => $thenStatus], $files !== []);
		$now = $this->time->getTime();
		$reply->setCreatedAt($now);
		$reply->setUpdatedAt($now);
		$reply = $this->mapper->insert($reply);
		$this->attachments->linkToScheduled($files, $reply->getId());
		return $reply;
	}

	/** @throws NotFoundException|ValidationException */
	public function update(string $uid, int $ticketId, int $id, array $data): ScheduledReply {
		$reply = $this->getOwn($uid, $ticketId, $id);
		if (!in_array($reply->getState(), [ScheduledReply::SCHEDULED, ScheduledReply::FAILED], true)) {
			throw new ValidationException('This reply is being sent already');
		}
		$this->apply($reply, $data, $this->attachments->forScheduled($id) !== []);
		// A failed reply with a new time gets another try.
		$reply->setState(ScheduledReply::SCHEDULED);
		$reply->setError(null);
		$reply->setUpdatedAt($this->time->getTime());
		return $this->mapper->update($reply);
	}

	/** @throws NotFoundException|ValidationException */
	public function cancel(string $uid, int $ticketId, int $id): ScheduledReply {
		$reply = $this->getOwn($uid, $ticketId, $id);
		if (!in_array($reply->getState(), [ScheduledReply::SCHEDULED, ScheduledReply::FAILED], true)
			|| !$this->mapper->claim($id, $reply->getState(), ScheduledReply::CANCELLED, $this->time->getTime())) {
			throw new ValidationException('This reply is being sent already');
		}
		$reply->setState(ScheduledReply::CANCELLED);
		$this->attachments->releaseScheduled($id);
		return $reply;
	}

	/**
	 * Sends a waiting reply right away.
	 *
	 * @throws NotFoundException|ValidationException
	 */
	public function sendNow(string $uid, int $ticketId, int $id): ScheduledReply {
		$reply = $this->getOwn($uid, $ticketId, $id);
		if (!in_array($reply->getState(), [ScheduledReply::SCHEDULED, ScheduledReply::FAILED], true)) {
			throw new ValidationException('This reply is being sent already');
		}
		if (!$this->deliver($reply, false)) {
			throw new ValidationException($reply->getError() ?? 'The reply could not be sent');
		}
		return $reply;
	}

	/** Sends every reply whose time has come (background job). Returns how many were sent. */
	public function sendDue(): int {
		$sent = 0;
		foreach ($this->mapper->findDue($this->time->getTime()) as $reply) {
			if ($this->deliver($reply, true)) {
				$sent++;
			}
		}
		return $sent;
	}

	private function deliver(ScheduledReply $reply, bool $fromJob): bool {
		$now = $this->time->getTime();
		if (!$this->mapper->claim($reply->getId(), $reply->getState(), ScheduledReply::SENDING, $now)) {
			return false;
		}
		try {
			$ticket = $this->tickets->get($reply->getTicketId());
			if (!$this->teams->isAgent($reply->getUid()) || !$this->teams->canAccess($reply->getUid(), $ticket->getTeamId())) {
				throw new ValidationException('The agent who scheduled this reply no longer works on this ticket\'s brand');
			}
			$fileIds = array_map(fn ($a) => $a->getId(), $this->attachments->forScheduled($reply->getId()));
			$message = $this->tickets->addAgentMessage($reply->getUid(), $reply->getTicketId(), $reply->getBody(), 'public', $fileIds, $reply->getId());
			$status = $reply->getThenStatus();
			if ($status !== null && $this->tickets->get($reply->getTicketId())->getStatus() !== $status) {
				$this->tickets->update($reply->getUid(), $reply->getTicketId(), ['status' => $status]);
			}
			$reply->setState(ScheduledReply::SENT);
			$reply->setMessageId($message->getId());
			$reply->setError(null);
			$reply->setUpdatedAt($this->time->getTime());
			$this->mapper->update($reply);
			if ($fromJob) {
				$this->notify($reply, 'scheduled_sent');
			}
			return true;
		} catch (\Throwable $e) {
			if (!$e instanceof ValidationException && !$e instanceof NotFoundException) {
				$this->logger->error('Ticket system could not send a scheduled reply', ['exception' => $e]);
			}
			$reply->setState(ScheduledReply::FAILED);
			$reply->setError(mb_substr($e->getMessage(), 0, 500));
			$reply->setUpdatedAt($this->time->getTime());
			$this->mapper->update($reply);
			if ($fromJob) {
				$this->notify($reply, 'scheduled_failed');
			}
			return false;
		}
	}

	private function notify(ScheduledReply $reply, string $subject): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($reply->getUid())
				->setDateTime($this->time->getDateTime())
				->setObject('ticket', (string)$reply->getTicketId())
				->setSubject($subject, ['ticketId' => $reply->getTicketId()]);
			$this->notifications->notify($notification);
		} catch (\Throwable $e) {
			$this->logger->warning('Ticket system could not notify about a scheduled reply', ['exception' => $e]);
		}
	}

	/** @throws NotFoundException|ValidationException */
	private function getOwn(string $uid, int $ticketId, int $id): ScheduledReply {
		try {
			$reply = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Scheduled reply not found');
		}
		if ($reply->getTicketId() !== $ticketId) {
			throw new NotFoundException('Scheduled reply not found');
		}
		if ($reply->getUid() !== $uid && !$this->groupManager->isAdmin($uid)) {
			throw new ValidationException('Only the agent who scheduled this reply can change it');
		}
		return $reply;
	}

	/** @throws ValidationException */
	private function apply(ScheduledReply $reply, array $data, bool $hasFiles): void {
		if (array_key_exists('body', $data)) {
			$body = trim((string)$data['body']);
			if ($body === '' && !$hasFiles) {
				throw new ValidationException('Message cannot be empty');
			}
			if (strlen($body) > 100000) {
				throw new ValidationException('Message is too long');
			}
			$reply->setBody($body);
		}
		if (array_key_exists('sendAt', $data)) {
			$sendAt = (int)$data['sendAt'];
			$now = $this->time->getTime();
			if ($sendAt < $now + self::MIN_AHEAD) {
				throw new ValidationException('Pick a time at least a minute from now');
			}
			if ($sendAt > $now + self::MAX_AHEAD) {
				throw new ValidationException('A reply can be scheduled at most a year ahead');
			}
			$reply->setSendAt($sendAt);
		}
		if (array_key_exists('thenStatus', $data)) {
			$status = $data['thenStatus'] === null || $data['thenStatus'] === '' ? null : (string)$data['thenStatus'];
			if ($status !== null && !in_array($status, $this->settings->getStatusKeys(), true)) {
				throw new ValidationException('Unknown status: ' . $status);
			}
			$reply->setThenStatus($status);
		}
	}
}
