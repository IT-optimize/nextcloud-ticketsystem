<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\MessageMapper;
use OCA\TicketSystem\Db\RelationMapper;
use OCA\TicketSystem\Db\ScheduledReplyMapper;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Db\TicketMapper;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * A customer's data under the GDPR: find it, export it, erase it, and erase
 * old tickets automatically.
 *
 * Erasing anonymises instead of deleting: the customer's address and name,
 * subjects, every message text, and files go, the portal
 * link stops working, and an unfinished ticket is closed. What stays is what
 * statistics and invoicing need: status, brand, assignee, times,
 * logged time.
 * Data is found by email address; an address that only appears inside some
 * other text is not found.
 */
class PrivacyService {
	public const ERASED_SUBJECT = 'Customer data erased';
	/** History details that may hold customer data. */
	private const PERSONAL_CHANGES = ['subject', 'customerEmail', 'customerName', 'cc'];
	private const RETENTION_BATCH = 200;

	public function __construct(
		private TicketMapper $ticketMapper,
		private MessageMapper $messageMapper,
		private RelationMapper $relations,
		private ScheduledReplyMapper $scheduled,
		private AttachmentService $attachments,
		private SettingsService $settings,
		private AgentService $agents,
		private IDBConnection $db,
		private ISecureRandom $random,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * What Ticket system holds about an address.
	 *
	 * @return array{email: string, tickets: list<array<string, mixed>>, copiedOn: int, otherMessages: int}
	 * @throws ValidationException
	 */
	public function summary(string $email): array {
		$email = $this->cleanEmail($email);
		$tickets = $this->ticketMapper->findAllByCustomer($email);
		$own = array_map(fn (Ticket $t) => $t->getId(), $tickets);
		return [
			'email' => $email,
			'tickets' => array_map(fn (Ticket $t) => [
				'id' => $t->getId(),
				'reference' => $t->getReference(),
				'subject' => $t->getSubject(),
				'status' => $t->getStatus(),
				'createdAt' => $t->getCreatedAt(),
				'erasedAt' => $t->getErasedAt(),
			], $tickets),
			'copiedOn' => count($this->ticketMapper->findWithCc($email)),
			'otherMessages' => count(array_filter($this->messageMapper->findByAuthorEmail($email), fn (Message $m) => !in_array($m->getTicketId(), $own, true))),
		];
	}

	/**
	 * Everything the customer can ask for (GDPR article 15): their tickets
	 * with the public conversation and file names, and what they wrote on
	 * other tickets. Internal notes are the team's own working notes and
	 * stay out.
	 *
	 * @throws ValidationException
	 */
	public function export(string $email): array {
		$email = $this->cleanEmail($email);
		$tickets = $this->ticketMapper->findAllByCustomer($email);
		$own = array_map(fn (Ticket $t) => $t->getId(), $tickets);
		$conversation = function (array $messages): array {
			$files = $this->attachments->forMessages(array_map(fn (Message $m) => $m->getId(), $messages));
			return array_values(array_map(fn (Message $m) => [
				'from' => $m->getAuthorType() === Message::AUTHOR_CUSTOMER ? ($m->getAuthorEmail() ?? 'customer') : $this->agents->getDisplayName($m->getAuthorUid()),
				'date' => date(DATE_ATOM, $m->getCreatedAt()),
				'text' => $m->getBody(),
				'files' => array_map(fn ($a) => $a->getFilename(), $files[$m->getId()] ?? []),
			], array_filter($messages, fn (Message $m) => $m->isPublic() && $m->getAuthorType() !== Message::AUTHOR_SYSTEM)));
		};
		return [
			'email' => $email,
			'exportedAt' => date(DATE_ATOM, $this->time->getTime()),
			'tickets' => array_map(fn (Ticket $t) => [
				'reference' => $t->getReference(),
				'subject' => $t->getSubject(),
				'name' => $t->getCustomerName(),
				'copied' => $t->getCcList(),
				'status' => $t->getStatus(),
				'opened' => date(DATE_ATOM, $t->getCreatedAt()),
				'conversation' => $conversation($this->messageMapper->findForTicket($t->getId(), true)),
			], $tickets),
			'writtenOnOtherTickets' => $conversation(array_values(array_filter(
				$this->messageMapper->findByAuthorEmail($email),
				fn (Message $m) => !in_array($m->getTicketId(), $own, true),
			))),
			'copiedOnTickets' => array_map(fn (Ticket $t) => $t->getReference(), $this->ticketMapper->findWithCc($email)),
		];
	}

	/**
	 * Erases an address everywhere (GDPR article 17): its tickets are
	 * anonymised, what it wrote on other tickets is removed, and it is taken
	 * off every CC list.
	 *
	 * @return array{tickets: int, messages: int, copiedOn: int}
	 * @throws ValidationException
	 */
	public function erase(string $email, ?string $actorUid): array {
		$email = $this->cleanEmail($email);
		$tickets = array_filter($this->ticketMapper->findAllByCustomer($email), fn (Ticket $t) => $t->getErasedAt() === null);
		foreach ($tickets as $ticket) {
			$this->eraseTicket($ticket, $actorUid);
		}

		$others = array_values(array_filter($this->messageMapper->findByAuthorEmail($email), fn (Message $m) => $m->getAuthorEmail() !== null));
		$this->attachments->deleteFor([], array_map(fn (Message $m) => $m->getId(), $others));
		foreach ($others as $message) {
			$this->blank($message);
		}

		$copied = $this->ticketMapper->findWithCc($email);
		foreach ($copied as $ticket) {
			$ticket->setCcList(array_values(array_diff($ticket->getCcList(), [$email])));
			$this->ticketMapper->update($ticket);
		}

		$this->logger->info(sprintf('Ticket system erased the data of a customer address: %d tickets, %d messages on other tickets, %d CC lists (by %s)',
			count($tickets), count($others), count($copied), $actorUid ?? 'system'));
		return ['tickets' => count($tickets), 'messages' => count($others), 'copiedOn' => count($copied)];
	}

	/**
	 * Erases tickets done longer ago than the retention setting allows
	 * (background job). Returns how many were erased.
	 */
	public function eraseExpired(): int {
		$months = $this->settings->retentionMonths();
		if ($months === 0) {
			return 0;
		}
		$before = $this->time->getTime() - $months * 30 * 86400;
		$tickets = $this->ticketMapper->findErasable($this->settings->getClosedStatusKeys(), $before, self::RETENTION_BATCH);
		foreach ($tickets as $ticket) {
			try {
				$this->eraseTicket($ticket, null);
			} catch (\Throwable $e) {
				$this->logger->error('Ticket system could not erase the customer data of ticket ' . $ticket->getId(), ['exception' => $e]);
			}
		}
		return count($tickets);
	}

	/** Anonymises one ticket; see the class description for what stays. */
	public function eraseTicket(Ticket $ticket, ?string $actorUid): void {
		$id = $ticket->getId();
		$now = $this->time->getTime();
		$this->attachments->deleteFor([$id]);
		$this->db->beginTransaction();
		try {
			foreach ($this->messageMapper->findForTicket($id, false) as $message) {
				if ($message->getAuthorType() === Message::AUTHOR_SYSTEM) {
					$this->scrubHistory($message);
				} else {
					$this->blank($message);
				}
			}
			$this->scheduled->clearForTicket($id, $now);

			$ticket->setSubject(self::ERASED_SUBJECT);
			$ticket->setCustomerEmail(null);
			$ticket->setCustomerName(null);
			$ticket->setCcList([]);
			$ticket->setAccessToken($this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC));
			$ticket->setErasedAt($now);
			// Nobody can answer it any more, so it leaves the work views.
			$closed = $this->settings->getClosedStatusKeys();
			if ($closed !== [] && !in_array($ticket->getStatus(), $closed, true)) {
				$ticket->setStatus($closed[0]);
				$ticket->setResolvedAt($now);
			}
			$ticket->setWaitingSince(null);
			$ticket->setSnoozedUntil(null);
			$this->ticketMapper->update($ticket);

			$entry = new Message();
			$entry->setTicketId($id);
			$entry->setAuthorType(Message::AUTHOR_SYSTEM);
			$entry->setAuthorUid($actorUid);
			$entry->setVisibility(Message::VISIBILITY_INTERNAL);
			$entry->setBody('The customer data of this ticket was erased.');
			$entry->setMetaData(['type' => 'erased']);
			$entry->setCreatedAt($now);
			$this->messageMapper->insert($entry);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/** A message without its text, sender address or email ids. */
	private function blank(Message $message): void {
		$message->setBody('');
		$message->setAuthorEmail(null);
		$message->setMailId(null);
		$message->setDeliveryError(null);
		$message->setMetaData(['erased' => true]);
		$this->messageMapper->update($message);
	}

	/**
	 * History keeps what was done (status, assignee, brand…) but not values
	 * that may be customer data. An entry with nothing left is removed.
	 */
	private function scrubHistory(Message $message): void {
		$meta = $message->getMetaData();
		if ($meta === null) {
			// Fixed sentences such as "The customer marked this request as solved."
			return;
		}
		$type = $meta['type'] ?? null;
		if ($type === 'change') {
			$meta['changes'] = array_filter(
				array_diff_key((array)($meta['changes'] ?? []), array_flip(self::PERSONAL_CHANGES)),
				fn (string $field) => !str_starts_with($field, 'field:'),
				ARRAY_FILTER_USE_KEY,
			);
			if ($meta['changes'] === []) {
				$this->messageMapper->delete($message);
				return;
			}
		} elseif ($type === 'attachmentsFailed') {
			$this->messageMapper->delete($message);
			return;
		}
		$message->setMetaData($meta === [] ? null : $meta);
		$message->setBody('');
		$this->messageMapper->update($message);
	}

	/** @throws ValidationException */
	private function cleanEmail(string $email): string {
		$email = strtolower(trim($email));
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new ValidationException('Enter the customer\'s email address');
		}
		return $email;
	}
}
