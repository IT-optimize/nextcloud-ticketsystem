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
 * @template-extends QBMapper<Company>
 */
class CompanyMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_companies', Company::class);
	}

	/** @return Company[] */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('name');
		return $this->findEntities($qb);
	}

	/** Links unlinked tickets from these email domains to the company. */
	public function claimTickets(int $companyId, array $domains): int {
		$changed = 0;
		foreach ($domains as $domain) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('ticketsystem_tickets')
				->set('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->isNull('company_id'))
				->andWhere($qb->expr()->iLike('customer_email', $qb->createNamedParameter('%@' . $this->db->escapeLikeParameter($domain))));
			$changed += $qb->executeStatement();
		}
		return $changed;
	}

	/** Unlinks every ticket from a deleted company. */
	public function releaseTickets(int $companyId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('ticketsystem_tickets')
			->set('company_id', $qb->createNamedParameter(null))
			->where($qb->expr()->eq('company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** @return array<int, int> number of tickets per company id */
	public function ticketCounts(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('company_id')
			->selectAlias($qb->func()->count('id'), 'n')
			->from('ticketsystem_tickets')
			->where($qb->expr()->isNotNull('company_id'))
			->groupBy('company_id');
		$rows = $qb->executeQuery();
		$counts = [];
		while ($row = $rows->fetch()) {
			$counts[(int)$row['company_id']] = (int)$row['n'];
		}
		$rows->closeCursor();
		return $counts;
	}
}
