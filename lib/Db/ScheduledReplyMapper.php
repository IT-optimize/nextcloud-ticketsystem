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
 * @template-extends QBMapper<ScheduledReply>
 */
class ScheduledReplyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_scheduled', ScheduledReply::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	/** Customer data erasure: no reply text stays, and nothing waiting goes out. */
	public function clearForTicket(int $ticketId, int $now): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(ScheduledReply::CANCELLED))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter([ScheduledReply::SCHEDULED, ScheduledReply::FAILED], IQueryBuilder::PARAM_STR_ARRAY)));
		$qb->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('body', $qb->createNamedParameter(''))
			->set('error', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function find(int $id): ScheduledReply {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return ScheduledReply[] waiting or failed replies of a ticket */
	public function findPending(int $ticketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter([ScheduledReply::SCHEDULED, ScheduledReply::SENDING, ScheduledReply::FAILED], IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('send_at');
		return $this->findEntities($qb);
	}

	/** @return ScheduledReply[] */
	public function findDue(int $now, int $limit = 100): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(ScheduledReply::SCHEDULED)))
			->andWhere($qb->expr()->lte('send_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
			->orderBy('send_at')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/**
	 * Moves a reply from one state to another only if nobody else did first,
	 * so two cron runs never send the same reply twice.
	 */
	public function claim(int $id, string $from, string $to, int $now): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter($to))
			->set('updated_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($from)));
		return $qb->executeStatement() === 1;
	}

	/** @return array<int, int> earliest waiting send time per ticket */
	public function nextFor(array $ticketIds): array {
		$result = [];
		foreach (array_chunk($ticketIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('ticket_id')
				->selectAlias($qb->func()->min('send_at'), 'next_at')
				->from($this->getTableName())
				->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(ScheduledReply::SCHEDULED)))
				->groupBy('ticket_id');
			$rows = $qb->executeQuery();
			while ($row = $rows->fetch()) {
				$result[(int)$row['ticket_id']] = (int)$row['next_at'];
			}
			$rows->closeCursor();
		}
		return $result;
	}
}
