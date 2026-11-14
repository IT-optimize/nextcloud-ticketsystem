<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CannedReply>
 */
class CannedReplyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_canned', CannedReply::class);
	}

	/**
	 * Shared replies plus the agent's own, most used first.
	 * @return CannedReply[]
	 */
	public function findVisibleTo(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->isNull('owner_uid'),
				$qb->expr()->eq('owner_uid', $qb->createNamedParameter($uid)),
			))
			->orderBy('usage_count', 'DESC')
			->addOrderBy('title', 'ASC');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): CannedReply {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** Atomic +1, so two agents inserting at once both count. */
	public function incrementUsage(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('usage_count', $qb->func()->add('usage_count', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
