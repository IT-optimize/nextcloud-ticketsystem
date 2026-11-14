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
 * @template-extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_attachments', Attachment::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): Attachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @param int[] $messageIds
	 * @return Attachment[]
	 */
	public function findForMessages(array $messageIds): array {
		if ($messageIds === []) {
			return [];
		}
		$found = [];
		foreach (array_chunk(array_values(array_unique($messageIds)), 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->in('message_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->orderBy('id');
			array_push($found, ...$this->findEntities($qb));
		}
		return $found;
	}

	public function existsFromSender(string $hash, string $email): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.id')
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'ticketsystem_messages', 'm', $qb->expr()->eq('m.id', 'a.message_id'))
			->where($qb->expr()->eq('a.content_hash', $qb->createNamedParameter($hash)))
			->andWhere($qb->expr()->eq('m.author_email', $qb->createNamedParameter(strtolower($email))))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();
		return $found;
	}

	/** @return Attachment[] every file of a ticket, also unsent uploads */
	public function findForTicket(int $ticketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return Attachment[] */
	public function findForScheduled(int $scheduledId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('scheduled_id', $qb->createNamedParameter($scheduledId, IQueryBuilder::PARAM_INT)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * @param int[] $ids
	 * @return Attachment[]
	 */
	public function findMany(array $ids): array {
		if ($ids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter(array_values(array_unique($ids)), IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Uploads that never reached a message or a scheduled reply.
	 *
	 * @return Attachment[]
	 */
	public function findAbandoned(int $before, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('message_id'))
			->andWhere($qb->expr()->isNull('scheduled_id'))
			->andWhere($qb->expr()->lt('created_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_INT)))
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}
}
