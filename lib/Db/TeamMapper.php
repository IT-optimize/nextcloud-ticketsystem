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
 * @template-extends QBMapper<Team>
 */
class TeamMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_teams', Team::class);
	}

	/** @return Team[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('sort_order')
			->addOrderBy('name');
		return $this->findEntities($qb);
	}

	/** @throws DoesNotExistException */
	public function find(int $id): Team {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function countTickets(int $teamId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('ticketsystem_tickets')
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$n = (int)$result->fetchOne();
		$result->closeCursor();
		return $n;
	}

	/** Moves every ticket (and brand-specific canned reply) to another brand. */
	public function moveTickets(int $fromTeamId, int $toTeamId): void {
		foreach (['ticketsystem_tickets', 'ticketsystem_canned'] as $table) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($table)
				->set('team_id', $qb->createNamedParameter($toTeamId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('team_id', $qb->createNamedParameter($fromTeamId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}
}
