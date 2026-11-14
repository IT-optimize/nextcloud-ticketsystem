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
 * @template-extends QBMapper<TimeEntry>
 */
class TimeEntryMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'ticketsystem_time', TimeEntry::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function find(int $id): TimeEntry {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return TimeEntry[] */
	public function findForTicket(int $ticketId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($ticketId, IQueryBuilder::PARAM_INT)))
			->orderBy('work_date', 'DESC')
			->addOrderBy('id', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return array<int, int> minutes per ticket */
	public function totalsFor(array $ticketIds): array {
		$totals = [];
		foreach (array_chunk($ticketIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('ticket_id')
				->selectAlias($qb->func()->sum('minutes'), 'total')
				->from($this->getTableName())
				->where($qb->expr()->in('ticket_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('ticket_id');
			$rows = $qb->executeQuery();
			while ($row = $rows->fetch()) {
				$totals[(int)$row['ticket_id']] = (int)$row['total'];
			}
			$rows->closeCursor();
		}
		return $totals;
	}

	/**
	 * Entries in a period, joined with their ticket's brand and company.
	 *
	 * @param int[]|null $teamIds null = every brand
	 * @return list<array{entry: TimeEntry, teamId: int, companyId: ?int, reference: string, subject: string, customerEmail: ?string}>
	 */
	public function search(string $from, string $to, ?array $teamIds, ?int $teamId, ?int $companyId, bool $noCompany, ?string $uid, ?bool $billable, ?bool $invoiced): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.*')
			->selectAlias('t.team_id', 'x_team_id')
			->selectAlias('t.company_id', 'x_company_id')
			->selectAlias('t.subject', 'x_subject')
			->selectAlias('t.customer_email', 'x_customer_email')
			->from($this->getTableName(), 'e')
			->innerJoin('e', 'ticketsystem_tickets', 't', $qb->expr()->eq('e.ticket_id', 't.id'))
			->where($qb->expr()->gte('e.work_date', $qb->createNamedParameter($from)))
			->andWhere($qb->expr()->lte('e.work_date', $qb->createNamedParameter($to)))
			->orderBy('e.work_date')
			->addOrderBy('e.id');
		if ($teamIds !== null) {
			if ($teamIds === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('t.team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		if ($teamId !== null) {
			$qb->andWhere($qb->expr()->eq('t.team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		}
		if ($noCompany) {
			$qb->andWhere($qb->expr()->isNull('t.company_id'));
		} elseif ($companyId !== null) {
			$qb->andWhere($qb->expr()->eq('t.company_id', $qb->createNamedParameter($companyId, IQueryBuilder::PARAM_INT)));
		}
		if ($uid !== null) {
			$qb->andWhere($qb->expr()->eq('e.uid', $qb->createNamedParameter($uid)));
		}
		if ($billable !== null) {
			$qb->andWhere($billable
				? $qb->expr()->eq('e.billable', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL))
				: $qb->expr()->orX($qb->expr()->eq('e.billable', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)), $qb->expr()->isNull('e.billable')));
		}
		if ($invoiced !== null) {
			$qb->andWhere($invoiced ? $qb->expr()->isNotNull('e.invoiced_at') : $qb->expr()->isNull('e.invoiced_at'));
		}
		$rows = $qb->executeQuery();
		$result = [];
		while ($row = $rows->fetch()) {
			$entryRow = array_filter($row, fn ($key) => !str_starts_with((string)$key, 'x_'), ARRAY_FILTER_USE_KEY);
			$result[] = [
				'entry' => $this->mapRowToEntity($entryRow),
				'teamId' => (int)$row['x_team_id'],
				'companyId' => $row['x_company_id'] !== null ? (int)$row['x_company_id'] : null,
				'subject' => (string)$row['x_subject'],
				'customerEmail' => $row['x_customer_email'],
			];
		}
		$rows->closeCursor();
		return $result;
	}

	public function moveTo(int $fromTicketId, int $toTicketId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('ticket_id', $qb->createNamedParameter($toTicketId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('ticket_id', $qb->createNamedParameter($fromTicketId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** @param int[] $ids */
	public function markInvoiced(array $ids, string $uid, int $now): int {
		$changed = 0;
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update($this->getTableName())
				->set('invoiced_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('invoiced_by', $qb->createNamedParameter($uid))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->isNull('invoiced_at'))
				->andWhere($qb->expr()->eq('billable', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
			$changed += $qb->executeStatement();
		}
		return $changed;
	}
}
