<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Message>
 */
class MessageMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_messages', Message::class);
	}

	/**
	 * @param bool $publicOnly true for anything customer-facing; it must never see internal notes
	 * @return Message[]
	 */
	public function findForTicket(int $ticketId, bool $publicOnly): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		if ($publicOnly) {
			$qb->andWhere($qb->expr()->eq('visibility', $qb->createNamedParameter(Message::VISIBILITY_PUBLIC)));
		}
		$qb->orderBy('created_at', 'ASC')->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function findForTicketById(int $ticketId, int $messageId): Message {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return Message[] everything this address wrote, on any ticket */
	public function findByAuthorEmail(string $email): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('author_email', $qb->createNamedParameter(strtolower($email))))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/** The message an email with this Message-ID belongs to (imported, or sent by Ticket system). */
	public function findByMailId(string $messageId): ?Message {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('mail_id_hash', $qb->createNamedParameter(Message::hashMailId($messageId))))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$found = $this->findEntities($qb);
		return $found[0] ?? null;
	}

	/** Whether an email with this Message-ID was already imported. */
	public function existsMailId(string $messageId): bool {
		return $this->findTicketIdByMailIds([$messageId]) !== null;
	}

	/**
	 * The ticket that already contains one of these Message-IDs (for threading
	 * via In-Reply-To / References), or null.
	 *
	 * @param string[] $messageIds
	 */
	public function findTicketIdByMailIds(array $messageIds): ?int {
		$hashes = array_values(array_unique(array_map([Message::class, 'hashMailId'], array_filter($messageIds))));
		if ($hashes === []) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id')
			->from($this->getTableName())
			->where($qb->expr()->in('mail_id_hash', $qb->createNamedParameter($hashes, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();
		return $id === false ? null : (int)$id;
	}

	/**
	 * Message-IDs of the customer's emails on a ticket, oldest first, for
	 * threading the answers to them.
	 *
	 * @return string[]
	 */
	public function customerMailIds(int $ticketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('mail_message_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('author_type', $qb->createNamedParameter(Message::AUTHOR_CUSTOMER)))
			->andWhere($qb->expr()->isNotNull('mail_message_id'))
			->orderBy('id', 'ASC');
		$result = $qb->executeQuery();
		$ids = [];
		while (($id = $result->fetchOne()) !== false) {
			// Made-up ids for emails that had none (see MailIntakeService) were never in any mailbox.
			if (!str_ends_with((string)$id, '@ticketsystem.invalid')) {
				$ids[] = (string)$id;
			}
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * The newest message and the newest public message of each ticket,
	 * history entries left out. Used for list previews and "needs reply".
	 *
	 * @param int[] $ticketIds
	 * @return array<int, array{latest: Message, latestPublic: ?Message}>
	 */
	public function findLatestForTickets(array $ticketIds): array {
		if ($ticketIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('ticket_id', 'visibility')
			->selectAlias($qb->func()->max('id'), 'last_id')
			->from($this->getTableName())
			->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($ticketIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->neq('author_type', $qb->createNamedParameter(Message::AUTHOR_SYSTEM)))
			->groupBy('ticket_id', 'visibility');
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['last_id'];
		}
		$result->closeCursor();
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
		$latest = [];
		foreach ($this->findEntities($qb) as $message) {
			$tid = $message->getTicketId();
			$entry = $latest[$tid] ?? ['latest' => $message, 'latestPublic' => null];
			if ($message->getId() > $entry['latest']->getId()) {
				$entry['latest'] = $message;
			}
			if ($message->isPublic()) {
				$entry['latestPublic'] = $message;
			}
			$latest[$tid] = $entry;
		}
		return $latest;
	}

	/** Id of the newest message of a ticket (history entries included), 0 if none. */
	public function lastIdForTicket(int $ticketId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$id = (int)$result->fetchOne();
		$result->closeCursor();
		return $id;
	}

	public function deleteForTicket(int $ticketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
