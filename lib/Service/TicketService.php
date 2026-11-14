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
use OCA\TicketSystem\Db\TicketFilter;
use OCA\TicketSystem\Db\TicketMapper;
use OCA\TicketSystem\Event\MessageAddedEvent;
use OCA\TicketSystem\Event\TicketAssignedEvent;
use OCA\TicketSystem\Event\TicketCreatedEvent;
use OCA\TicketSystem\Event\TicketUpdatedEvent;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The single entry point for changing tickets. Controllers, background jobs
 * and add-in apps should all go through here so events are always dispatched.
 *
 * Which tickets an agent may see is decided by brand: use getForAgent() and
 * ViewService::buildFilter() for anything an agent asked for. Methods that
 * take a ticket id trust that the caller checked access.
 */
class TicketService {
	private const MAX_SUBJECT = 255;
	private const MAX_BODY = 100000;

	public function __construct(
		private TicketMapper $ticketMapper,
		private MessageMapper $messageMapper,
		private RelationMapper $relations,
		private SettingsService $settings,
		private AgentService $agents,
		private TeamService $teams,
		private TagService $tags,
		private ViewService $views,
		private CompanyService $companies,
		private ScheduledReplyMapper $scheduled,
		private AttachmentService $attachments,
		private LoggerInterface $logger,
		private IEventDispatcher $dispatcher,
		private ITimeFactory $time,
		private ISecureRandom $random,
		private IDBConnection $db,
	) {
	}

	// --- Reading ------------------------------------------------------------

	/** @return Ticket[] */
	public function search(TicketFilter $filter, int $limit = 50, int $offset = 0): array {
		return $this->ticketMapper->search($filter, max(1, min($limit, 200)), max(0, $offset));
	}

	public function count(TicketFilter $filter): int {
		return $this->ticketMapper->count($filter);
	}

	/**
	 * Tickets of a view as this agent may see them.
	 *
	 * @return Ticket[]
	 * @throws NotFoundException
	 */
	public function listView(string $uid, string $viewId, ?string $query = null, int $limit = 50, int $offset = 0): array {
		return $this->search($this->views->buildFilter($uid, $this->views->specFor($uid, $viewId), $query), $limit, $offset);
	}

	/** @return array<string, int> number of tickets per view */
	public function counts(string $uid): array {
		$counts = [];
		foreach ($this->views->listFor($uid) as $view) {
			if (in_array($view['id'], ['closed', 'all'], true)) {
				continue;
			}
			$counts[$view['id']] = $this->count($this->views->buildFilter($uid, $view['filter']));
		}
		return $counts;
	}

	/**
	 * Tickets as the agent list shows them: each with its tags, a preview of
	 * its newest message and whether the customer is waiting for an answer.
	 *
	 * @param Ticket[] $tickets
	 * @return list<array<string, mixed>>
	 */
	public function withPreviews(array $tickets): array {
		$ids = array_map(fn (Ticket $t) => $t->getId(), $tickets);
		$latest = $this->messageMapper->findLatestForTickets($ids);
		$tagIds = $this->relations->tagIdsFor($ids);
		$scheduled = $ids === [] ? [] : $this->scheduled->nextFor($ids);
		$closed = $this->settings->getClosedStatusKeys();
		// Messages that are only files are previewed by their file names.
		$fileOnly = array_map(fn (array $e) => $e['latest']->getId(), array_filter($latest, fn (array $e) => trim($e['latest']->getBody()) === ''));
		$files = $this->attachments->forMessages(array_values($fileOnly));
		$rows = [];
		foreach ($tickets as $ticket) {
			$entry = $latest[$ticket->getId()] ?? null;
			$row = $ticket->jsonSerialize();
			$row['tagIds'] = $tagIds[$ticket->getId()] ?? [];
			$row['lastMessage'] = null;
			if ($entry !== null) {
				$message = $entry['latest'];
				$names = array_map(fn ($a) => $a->getFilename(), $files[$message->getId()] ?? []);
				$row['lastMessage'] = [
					'authorType' => $message->getAuthorType(),
					'authorUid' => $message->getAuthorUid(),
					'visibility' => $message->getVisibility(),
					'excerpt' => $names !== [] ? $this->excerpt('📎 ' . implode(', ', $names)) : $this->excerpt($message->getBody()),
					'createdAt' => $message->getCreatedAt(),
				];
			}
			$row['needsReply'] = $ticket->getWaitingSince() !== null && !in_array($ticket->getStatus(), $closed, true);
			$row['scheduledAt'] = $scheduled[$ticket->getId()] ?? null;
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Everything the ticket page shows beside the thread.
	 *
	 * @return array<string, mixed>
	 */
	public function details(string $uid, Ticket $ticket): array {
		$id = $ticket->getId();
		$visible = fn (Ticket $t) => $this->teams->canAccess($uid, $t->getTeamId());
		$brief = fn (Ticket $t) => [
			'id' => $t->getId(),
			'reference' => $t->getReference(),
			'subject' => $t->getSubject(),
			'status' => $t->getStatus(),
			'createdAt' => $t->getCreatedAt(),
		];
		$others = $ticket->getCustomerEmail() === null ? []
			: array_values(array_filter($this->ticketMapper->findByCustomer($ticket->getCustomerEmail(), $id, 10), $visible));
		return [
			'tagIds' => $this->relations->tagIdsFor([$id])[$id] ?? [],
			'otherTickets' => array_map($brief, array_slice($others, 0, 5)),
		];
	}

	/**
	 * Changes whenever anything visible on the ticket changes, so open
	 * ticket pages know when to reload.
	 */
	public function version(int $id): string {
		$ticket = $this->get($id);
		return $ticket->getUpdatedAt() . '-' . $this->messageMapper->lastIdForTicket($id);
	}

	/** @throws NotFoundException */
	public function get(int $id): Ticket {
		try {
			return $this->ticketMapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Ticket not found');
		}
	}

	/**
	 * A ticket this agent may work on. Tickets of other brands look the same
	 * as missing ones.
	 *
	 * @throws NotFoundException
	 */
	public function getForAgent(string $uid, int $id): Ticket {
		$ticket = $this->get($id);
		if (!$this->teams->canAccess($uid, $ticket->getTeamId())) {
			throw new NotFoundException('Ticket not found');
		}
		return $ticket;
	}

	/**
	 * The ticket behind a customer portal link.
	 * @throws NotFoundException
	 */
	public function getByToken(string $token): Ticket {
		if (!preg_match('/^[A-Za-z0-9]{32,64}$/', $token)) {
			throw new NotFoundException('Ticket not found');
		}
		try {
			$ticket = $this->ticketMapper->findByToken($token);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Ticket not found');
		}
		// Guard against case-insensitive collations matching a different token.
		if (!hash_equals($ticket->getAccessToken(), $token)) {
			throw new NotFoundException('Ticket not found');
		}
		return $ticket;
	}

	/** @throws NotFoundException */
	public function getMessage(int $ticketId, int $messageId): Message {
		try {
			return $this->messageMapper->findForTicketById($ticketId, $messageId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Message not found');
		}
	}

	/** @return Message[] */
	public function getMessages(int $ticketId, bool $publicOnly): array {
		return $this->messageMapper->findForTicket($ticketId, $publicOnly);
	}

	/** @return string[] who hears about this ticket: the assignee, or else the brand's agents */
	public function responsibleFor(Ticket $ticket): array {
		if ($ticket->getAssigneeUid() !== null) {
			return [$ticket->getAssigneeUid()];
		}
		// Nobody assigned: everyone who works on the brand, except admins who
		// only see it because they see everything.
		$uids = [];
		foreach ($this->agents->listAgents() as $agent) {
			if ($agent['teamIds'] !== null && in_array($ticket->getTeamId(), $agent['teamIds'], true)) {
				$uids[] = $agent['uid'];
			}
		}
		return $uids;
	}

	// --- Creating and changing ----------------------------------------------

	/**
	 * Create a ticket. $body becomes the first message, recorded as the
	 * customer's request (public, but not emailed back to them).
	 *
	 * @param array{subject?: string, body?: string, customerEmail?: ?string, customerName?: ?string, cc?: string[], priority?: ?string, assigneeUid?: ?string, teamId?: ?int, tagIds?: int[], channel?: string, mailMessageId?: ?string, files?: list<array{filename: string, contentType: string, content: string}>} $data
	 *        files: attachments of the customer's first email
	 * @throws ValidationException
	 */
	public function create(?string $actorUid, array $data): Ticket {
		$subject = $this->cleanSubject($data['subject'] ?? '');
		$body = trim((string)($data['body'] ?? ''));
		$files = $data['files'] ?? [];
		$email = $this->cleanEmail($data['customerEmail'] ?? null);
		$name = $this->cleanOptional($data['customerName'] ?? null, 255);
		$cc = $this->cleanCc($data['cc'] ?? [], $email);
		$priority = $data['priority'] ?? null ?: $this->settings->getDefaultPriority();
		$this->assertPriority($priority);
		$teamId = $this->resolveTeam($actorUid, $data['teamId'] ?? null);
		$assignee = $data['assigneeUid'] ?? null ?: null;
		if ($assignee !== null) {
			$this->assertAssignable($assignee, $teamId);
		}
		$tagIds = $this->cleanTagIds($data['tagIds'] ?? []);
		if (strlen($body) > self::MAX_BODY) {
			throw new ValidationException('Message is too long');
		}

		$now = $this->time->getTime();
		$ticket = new Ticket();
		$ticket->setSubject($subject);
		$ticket->setStatus($this->settings->getDefaultStatus());
		$ticket->setPriority($priority);
		$ticket->setTeamId($teamId);
		$ticket->setCustomerEmail($email);
		$ticket->setCustomerName($name);
		$ticket->setCcList($cc);
		$ticket->setCompanyId($this->cleanCompanyId($data['companyId'] ?? null) ?? $this->companies->matchEmail($email)?->getId());
		$ticket->setAssigneeUid($assignee);
		$ticket->setCreatedBy($actorUid);
		$ticket->setChannel(preg_match('/^[a-z0-9_-]{1,32}$/', $data['channel'] ?? '') ? $data['channel'] : 'manual');
		$ticket->setAccessToken($this->random->generate(48, ISecureRandom::CHAR_ALPHANUMERIC));
		$ticket->setCreatedAt($now);
		$ticket->setUpdatedAt($now);
		if ($body !== '' || $files !== []) {
			$ticket->setWaitingSince($now);
		}

		$message = null;
		$this->db->beginTransaction();
		try {
			$ticket = $this->ticketMapper->insert($ticket);
			if ($body !== '' || $files !== []) {
				$message = new Message();
				$message->setTicketId($ticket->getId());
				$message->setAuthorType(Message::AUTHOR_CUSTOMER);
				$message->setAuthorEmail($email);
				$message->setMailId($data['mailMessageId'] ?? null);
				$message->setVisibility(Message::VISIBILITY_PUBLIC);
				$message->setBody($body);
				$message->setCreatedAt($now);
				$message = $this->messageMapper->insert($message);
			}
			if ($tagIds !== []) {
				$this->relations->setTags($ticket->getId(), $tagIds);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
		if ($message !== null) {
			$this->storeFiles($ticket, $message, $files);
		}

		$this->dispatcher->dispatchTyped(new TicketCreatedEvent($ticket, $actorUid));
		if ($message !== null) {
			$this->dispatcher->dispatchTyped(new MessageAddedEvent($ticket, $actorUid, $message));
		}
		if ($assignee !== null) {
			$this->dispatcher->dispatchTyped(new TicketAssignedEvent($ticket, $actorUid, null));
		}
		return $ticket;
	}

	/**
	 * Partial update. Fields: status, priority, assigneeUid, subject,
	 * customerEmail, customerName, cc (the full list), teamId, snoozedUntil (unix time or
	 * null), tagIds (the full list).
	 *
	 * @throws NotFoundException|ValidationException
	 */
	public function update(?string $actorUid, int $id, array $fields): Ticket {
		$ticket = $this->get($id);
		$changes = [];
		$previousAssignee = $ticket->getAssigneeUid();
		$now = $this->time->getTime();

		if (array_key_exists('teamId', $fields)) {
			$teamId = $this->resolveTeam($actorUid, $fields['teamId']);
			$this->track($changes, 'teamId', $ticket->getTeamId(), $teamId);
			$ticket->setTeamId($teamId);
			// An assignee who doesn't work on the new brand lets go of the ticket.
			$assignee = $ticket->getAssigneeUid();
			if ($assignee !== null && !array_key_exists('assigneeUid', $fields) && !$this->agents->worksOn($assignee, $teamId)) {
				$fields['assigneeUid'] = null;
			}
		}
		if (array_key_exists('status', $fields)) {
			$status = (string)$fields['status'];
			if (!in_array($status, $this->settings->getStatusKeys(), true)) {
				throw new ValidationException('Unknown status: ' . $status);
			}
			$closed = $this->settings->getClosedStatusKeys();
			if (in_array($status, $closed, true) && !in_array($ticket->getStatus(), $closed, true)) {
				$ticket->setResolvedAt($now);
			}
			$this->track($changes, 'status', $ticket->getStatus(), $status);
			$ticket->setStatus($status);
		}
		if (array_key_exists('priority', $fields)) {
			$priority = (string)$fields['priority'];
			$this->assertPriority($priority);
			$this->track($changes, 'priority', $ticket->getPriority(), $priority);
			$ticket->setPriority($priority);
		}
		if (array_key_exists('assigneeUid', $fields)) {
			$assignee = $fields['assigneeUid'] ?: null;
			if ($assignee !== null) {
				$this->assertAssignable((string)$assignee, $ticket->getTeamId());
			}
			$this->track($changes, 'assigneeUid', $previousAssignee, $assignee);
			$ticket->setAssigneeUid($assignee);
		}
		if (array_key_exists('subject', $fields)) {
			$subject = $this->cleanSubject($fields['subject']);
			$this->track($changes, 'subject', $ticket->getSubject(), $subject);
			$ticket->setSubject($subject);
		}
		if (array_key_exists('customerEmail', $fields)) {
			$email = $this->cleanEmail($fields['customerEmail']);
			$this->track($changes, 'customerEmail', $ticket->getCustomerEmail(), $email);
			$ticket->setCustomerEmail($email);
		}
		if (array_key_exists('companyId', $fields)) {
			$companyId = $this->cleanCompanyId($fields['companyId']);
			$this->track($changes, 'companyId', $ticket->getCompanyId(), $companyId);
			$ticket->setCompanyId($companyId);
		} elseif (isset($changes['customerEmail']) && $ticket->getCompanyId() === null) {
			// A new address may tell which company the customer works for.
			$companyId = $this->companies->matchEmail($ticket->getCustomerEmail())?->getId();
			$this->track($changes, 'companyId', null, $companyId);
			$ticket->setCompanyId($companyId);
		}
		if (array_key_exists('customerName', $fields)) {
			$name = $this->cleanOptional($fields['customerName'], 255);
			$this->track($changes, 'customerName', $ticket->getCustomerName(), $name);
			$ticket->setCustomerName($name);
		}
		if (array_key_exists('cc', $fields) || isset($changes['customerEmail'])) {
			$cc = $this->cleanCc($fields['cc'] ?? $ticket->getCcList(), $ticket->getCustomerEmail());
			$this->track($changes, 'cc', $ticket->getCcList(), $cc);
			$ticket->setCcList($cc);
		}
		if (array_key_exists('snoozedUntil', $fields)) {
			$until = $fields['snoozedUntil'] !== null && $fields['snoozedUntil'] !== '' ? (int)$fields['snoozedUntil'] : null;
			if ($until !== null && $until <= $now) {
				throw new ValidationException('Snooze until a time in the future');
			}
			if ($until !== null && $until > $now + 366 * 86400) {
				throw new ValidationException('A ticket can be snoozed for at most a year');
			}
			$this->track($changes, 'snoozedUntil', $ticket->getSnoozedUntil(), $until);
			$ticket->setSnoozedUntil($until);
		}

		// Tags live in their own table.
		$newTags = null;
		if (array_key_exists('tagIds', $fields)) {
			$newTags = $this->cleanTagIds($fields['tagIds']);
			$oldTags = $this->relations->tagIdsFor([$id])[$id] ?? [];
			sort($oldTags);
			sort($newTags);
			$this->track($changes, 'tagIds', $oldTags, $newTags);
		}

		if ($changes === []) {
			return $ticket;
		}
		$ticket->setUpdatedAt($now);
		$this->db->beginTransaction();
		try {
			$ticket = $this->ticketMapper->update($ticket);
			if ($newTags !== null && isset($changes['tagIds'])) {
				$this->relations->setTags($id, $newTags);
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->dispatcher->dispatchTyped(new TicketUpdatedEvent($ticket, $actorUid, $changes));
		if (isset($changes['assigneeUid'])) {
			$this->dispatcher->dispatchTyped(new TicketAssignedEvent($ticket, $actorUid, $previousAssignee));
		}
		return $ticket;
	}

	/**
	 * An agent adds a public reply (emailed to the customer) or an internal note.
	 *
	 * @param int[] $attachmentIds the agent's uploads for this message (see AttachmentService)
	 * @param int|null $fromScheduledId the scheduled reply being sent, whose files come along
	 * @throws NotFoundException|ValidationException
	 */
	public function addAgentMessage(string $actorUid, int $ticketId, string $body, string $visibility, array $attachmentIds = [], ?int $fromScheduledId = null): Message {
		if (!in_array($visibility, [Message::VISIBILITY_PUBLIC, Message::VISIBILITY_INTERNAL], true)) {
			throw new ValidationException('Visibility must be public or internal');
		}
		$ticket = $this->get($ticketId);
		if ($visibility === Message::VISIBILITY_PUBLIC && $ticket->getCustomerEmail() === null) {
			throw new ValidationException('This ticket has no customer email address to reply to');
		}
		$files = $attachmentIds !== [] ? $this->attachments->claim($actorUid, $ticketId, $attachmentIds, $fromScheduledId) : [];
		if ($visibility === Message::VISIBILITY_PUBLIC && $ticket->getFirstResponseAt() === null) {
			$ticket->setFirstResponseAt($this->time->getTime());
		}
		$message = $this->storeMessage($ticket, Message::AUTHOR_AGENT, $actorUid, null, $visibility, $body, null, $files !== []);
		$this->attachments->linkToMessage($files, $message->getId());

		// First public answer moves a brand-new ticket into work.
		if ($visibility === Message::VISIBILITY_PUBLIC && $ticket->getStatus() === 'new'
			&& in_array('open', $this->settings->getStatusKeys(), true)) {
			$this->update($actorUid, $ticketId, ['status' => 'open']);
			$ticket = $this->get($ticketId);
		}

		$this->dispatcher->dispatchTyped(new MessageAddedEvent($ticket, $actorUid, $message));
		return $message;
	}

	/**
	 * A customer wrote (email or portal). Reopens done or waiting tickets and
	 * ends a snooze, so the ticket shows up again for the team.
	 * ticket shows up again for the team.
	 *
	 * @param list<array{filename: string, contentType: string, content: string}> $files attachments of the email
	 * @throws NotFoundException|ValidationException
	 */
	public function addCustomerMessage(int $ticketId, ?string $fromEmail, string $body, ?string $mailMessageId = null, array $files = []): Message {
		$ticket = $this->get($ticketId);
		$ticketId = $ticket->getId();
		$message = $this->storeMessage($ticket, Message::AUTHOR_CUSTOMER, null, $this->cleanEmail($fromEmail), Message::VISIBILITY_PUBLIC, $body, $mailMessageId, $files !== []);
		$this->storeFiles($ticket, $message, $files);

		$fields = [];
		$waiting = array_merge(['pending'], $this->settings->getClosedStatusKeys());
		if (in_array($ticket->getStatus(), $waiting, true) && in_array('open', $this->settings->getStatusKeys(), true)) {
			$fields['status'] = 'open';
		}
		if ($ticket->getSnoozedUntil() !== null) {
			$fields['snoozedUntil'] = null;
		}
		if ($fields !== []) {
			$this->update(null, $ticketId, $fields);
			$ticket = $this->get($ticketId);
		}

		$this->dispatcher->dispatchTyped(new MessageAddedEvent($ticket, null, $message));
		return $message;
	}

	/**
	 * Ends snoozes whose time is up (background job).
	 *
	 * @return Ticket[] the tickets that woke up
	 */
	public function wakeSnoozed(): array {
		$woken = [];
		foreach ($this->ticketMapper->findSnoozeEnded($this->time->getTime()) as $ticket) {
			$woken[] = $this->update(null, $ticket->getId(), ['snoozedUntil' => null]);
		}
		return $woken;
	}

	/**
	 * The customer says their request is solved (customer portal): moves the
	 * ticket to the first "done" status and leaves a visible note.
	 *
	 * @throws NotFoundException
	 */
	public function resolveByCustomer(int $ticketId): Ticket {
		$ticket = $this->get($ticketId);
		$closed = $this->settings->getClosedStatusKeys();
		if ($closed === [] || in_array($ticket->getStatus(), $closed, true)) {
			return $ticket;
		}
		$message = $this->storeMessage($ticket, Message::AUTHOR_SYSTEM, null, null, Message::VISIBILITY_PUBLIC, 'The customer marked this request as solved.', null);
		$ticket = $this->update(null, $ticketId, ['status' => $closed[0], 'snoozedUntil' => null]);
		$this->dispatcher->dispatchTyped(new MessageAddedEvent($ticket, null, $message));
		return $ticket;
	}

	/**
	 * A history entry in the thread (who changed what). Internal, never
	 * emailed, and it fires no events of its own.
	 *
	 * @param array<string, mixed> $meta structured data the UI renders, e.g. ['type' => 'change', 'changes' => …]
	 */
	public function addHistoryEntry(Ticket $ticket, ?string $actorUid, array $meta, string $fallbackText): Message {
		$message = new Message();
		$message->setTicketId($ticket->getId());
		$message->setAuthorType(Message::AUTHOR_SYSTEM);
		$message->setAuthorUid($actorUid);
		$message->setVisibility(Message::VISIBILITY_INTERNAL);
		$message->setBody($fallbackText);
		$message->setMetaData($meta);
		$message->setCreatedAt($this->time->getTime());
		return $this->messageMapper->insert($message);
	}

	/**
	 * Saves a customer's attachments on their message. A file that can't be
	 * saved must not lose the email: it is logged and noted on the ticket.
	 *
	 * @param list<array{filename: string, contentType: string, content: string}> $files
	 */
	private function storeFiles(Ticket $ticket, Message $message, array $files): void {
		$failed = [];
		foreach ($files as $file) {
			try {
				$this->attachments->store($ticket->getId(), $message->getId(), $file['filename'], $file['contentType'], $file['content'], null);
			} catch (\Throwable $e) {
				$this->logger->error('Ticket system could not save an attachment of ticket ' . $ticket->getId(), ['exception' => $e]);
				$failed[] = AttachmentService::cleanFilename($file['filename']);
			}
		}
		if ($failed !== []) {
			$this->addHistoryEntry($ticket, null, ['type' => 'attachmentsFailed', 'names' => $failed],
				'These attachments could not be saved and are only in the support inbox: ' . implode(', ', $failed));
		}
	}

	private function storeMessage(Ticket $ticket, string $authorType, ?string $uid, ?string $email, string $visibility, string $body, ?string $mailMessageId, bool $hasFiles = false): Message {
		$body = trim($body);
		if ($body === '' && !$hasFiles) {
			throw new ValidationException('Message cannot be empty');
		}
		if (strlen($body) > self::MAX_BODY) {
			throw new ValidationException('Message is too long');
		}
		$now = $this->time->getTime();
		$message = new Message();
		$message->setTicketId($ticket->getId());
		$message->setAuthorType($authorType);
		$message->setAuthorUid($uid);
		$message->setAuthorEmail($email);
		$message->setVisibility($visibility);
		$message->setBody($body);
		$message->setMailId($mailMessageId);
		$message->setCreatedAt($now);
		$message = $this->messageMapper->insert($message);

		// Who is the conversation waiting on: the customer's unanswered message, or nobody.
		if ($visibility === Message::VISIBILITY_PUBLIC && $authorType === Message::AUTHOR_CUSTOMER && $ticket->getWaitingSince() === null) {
			$ticket->setWaitingSince($now);
		} elseif ($visibility === Message::VISIBILITY_PUBLIC && $authorType === Message::AUTHOR_AGENT) {
			$ticket->setWaitingSince(null);
		}
		$ticket->setUpdatedAt($now);
		$this->ticketMapper->update($ticket);
		return $message;
	}

	/** Bumps updated_at, so open pages and lists notice a change. */
	/** Marks a ticket as changed, so open ticket pages reload it. */
	public function touch(int $id): void {
		$ticket = $this->get($id);
		$ticket->setUpdatedAt($this->time->getTime());
		$this->ticketMapper->update($ticket);
	}

	/**
	 * The brand for a new or moved ticket: the given one if the actor works
	 * on it, otherwise the default brand.
	 *
	 * @throws ValidationException
	 */
	private function resolveTeam(?string $actorUid, mixed $teamId): int {
		if ($teamId === null || $teamId === '' || (int)$teamId <= 0) {
			if ($actorUid !== null) {
				// The actor's own brand, if they only have one; the default brand if it is theirs.
				$mine = $this->teams->teamsFor($actorUid);
				$default = $this->teams->getDefault();
				foreach ($mine as $team) {
					if ($team->getId() === $default->getId()) {
						return $default->getId();
					}
				}
				if ($mine !== []) {
					return $mine[0]->getId();
				}
			}
			return $this->teams->getDefault()->getId();
		}
		$team = $this->teams->find((int)$teamId);
		if ($team === null || ($actorUid !== null && !$this->teams->canAccess($actorUid, $team->getId()))) {
			throw new ValidationException('Unknown brand');
		}
		return $team->getId();
	}

	private function track(array &$changes, string $field, mixed $old, mixed $new): void {
		if ($old !== $new) {
			$changes[$field] = ['old' => $old, 'new' => $new];
		}
	}

	private function excerpt(string $body): string {
		$text = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
		return mb_strlen($text) > 140 ? rtrim(mb_substr($text, 0, 139)) . '…' : $text;
	}

	private function assertPriority(string $priority): void {
		if (!in_array($priority, array_column($this->settings->getPriorities(), 'key'), true)) {
			throw new ValidationException('Unknown priority: ' . $priority);
		}
	}

	private function assertAssignable(string $uid, ?int $teamId): void {
		if (!$this->agents->worksOn($uid, $teamId)) {
			throw new ValidationException('Tickets can only be assigned to agents of the ticket\'s brand');
		}
	}

	/** @return int[] */
	private function cleanTagIds(mixed $ids): array {
		$ids = array_values(array_unique(array_map('intval', is_array($ids) ? $ids : [])));
		$unknown = array_diff($ids, $this->tags->allIds());
		if ($unknown !== []) {
			throw new ValidationException('Unknown tag');
		}
		return $ids;
	}

	private function cleanSubject(mixed $subject): string {
		$subject = trim(preg_replace('/\s+/u', ' ', (string)$subject) ?? '');
		if ($subject === '') {
			throw new ValidationException('Subject is required');
		}
		return mb_substr($subject, 0, self::MAX_SUBJECT);
	}

	private function cleanEmail(mixed $email): ?string {
		$email = $this->cleanOptional($email, 255);
		if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			throw new ValidationException('Not a valid email address: ' . $email);
		}
		return $email !== null ? strtolower($email) : null;
	}

	/**
	 * CC addresses: valid, lower-cased, without duplicates, the customer or
	 * Ticket system's own addresses.
	 *
	 * @return string[]
	 * @throws ValidationException
	 */
	private function cleanCc(mixed $list, ?string $customerEmail): array {
		if (is_string($list)) {
			$list = preg_split('/[\s,;]+/', $list) ?: [];
		}
		$own = $this->teams->ownAddresses();
		$clean = [];
		foreach (is_array($list) ? $list : [] as $address) {
			$address = $this->cleanEmail($address);
			if ($address === null || $address === $customerEmail || in_array($address, $own, true)) {
				continue;
			}
			$clean[$address] = true;
		}
		if (count($clean) > Ticket::MAX_CC) {
			throw new ValidationException(sprintf('At most %d people can be copied on a ticket', Ticket::MAX_CC));
		}
		return array_keys($clean);
	}

	/** @throws ValidationException */
	private function cleanCompanyId(mixed $id): ?int {
		if ($id === null || $id === '' || (int)$id <= 0) {
			return null;
		}
		if ($this->companies->find((int)$id) === null) {
			throw new ValidationException('Unknown company');
		}
		return (int)$id;
	}

	private function cleanOptional(mixed $value, int $max): ?string {
		if ($value === null) {
			return null;
		}
		$value = trim((string)$value);
		return $value === '' ? null : mb_substr($value, 0, $max);
	}
}
