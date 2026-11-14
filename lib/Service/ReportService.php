<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Exception\ValidationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Numbers for the reports page, for the brands an agent works on:
 * ticket volume, first response and resolution times, and the same per agent.
 *
 * Times are calendar time (what the customer experiences).
 */
class ReportService {
	private const MAX_DAYS = 366;

	public function __construct(
		private IDBConnection $db,
		private TeamService $teams,
		private SettingsService $settings,
	) {
	}

	/**
	 * @param string $from first day, Y-m-d
	 * @param string $to last day, Y-m-d (inclusive)
	 * @throws ValidationException
	 */
	public function build(string $uid, string $from, string $to, ?int $teamId, string $timezone): array {
		[$zone, $start, $end, $days] = $this->range($from, $to, $timezone);
		$teamIds = $this->teamIds($uid, $teamId);
		$rows = $this->load($teamIds, $start, $end);
		$closed = $this->settings->getClosedStatusKeys();

		$inRange = fn (?int $ts) => $ts !== null && $ts >= $start && $ts < $end;
		$created = array_values(array_filter($rows, fn ($r) => $inRange($r['created_at'])));
		$resolved = array_values(array_filter($rows, fn ($r) => $inRange($r['resolved_at']) && in_array($r['status'], $closed, true)));

		// Daily series in the agent's timezone.
		$series = [];
		$day = (new \DateTimeImmutable('@' . $start))->setTimezone($zone);
		for ($i = 0; $i < $days; $i++) {
			$series[$day->format('Y-m-d')] = ['date' => $day->format('Y-m-d'), 'created' => 0, 'resolved' => 0];
			$day = $day->modify('+1 day');
		}
		$dayOf = fn (int $ts) => (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format('Y-m-d');
		foreach ($created as $r) {
			$series[$dayOf($r['created_at'])]['created']++;
		}
		foreach ($resolved as $r) {
			$series[$dayOf($r['resolved_at'])]['resolved']++;
		}

		$firstResponseTimes = [];
		foreach ($created as $r) {
			if ($r['first_response_at'] !== null) {
				$firstResponseTimes[] = $r['first_response_at'] - $r['created_at'];
			}
		}
		$resolutionTimes = [];
		foreach ($resolved as $r) {
			$resolutionTimes[] = max(0, $r['resolved_at'] - $r['created_at']);
		}

		$open = $this->countOpen($teamIds);

		return [
			'from' => $from,
			'to' => $to,
			'totals' => [
				'created' => count($created),
				'resolved' => count($resolved),
				'open' => $open['total'],
				'unassigned' => $open['unassigned'],
			],
			'series' => array_values($series),
			'firstResponse' => [
				'median' => $this->median($firstResponseTimes),
				'average' => $this->average($firstResponseTimes),
				'count' => count($firstResponseTimes),
			],
			'resolution' => [
				'median' => $this->median($resolutionTimes),
				'average' => $this->average($resolutionTimes),
				'count' => count($resolutionTimes),
			],
			'byAgent' => $this->byAgent($created, $resolved),
		];
	}


	/** @return array{0: \DateTimeZone, 1: int, 2: int, 3: int} */
	private function range(string $from, string $to, string $timezone): array {
		try {
			$zone = new \DateTimeZone($timezone);
		} catch (\Exception) {
			$zone = new \DateTimeZone('Europe/Copenhagen');
		}
		$first = \DateTimeImmutable::createFromFormat('!Y-m-d', $from, $zone);
		$last = \DateTimeImmutable::createFromFormat('!Y-m-d', $to, $zone);
		if ($first === false || $last === false || $first->format('Y-m-d') !== $from || $last->format('Y-m-d') !== $to) {
			throw new ValidationException('Dates are written like 2026-10-01');
		}
		if ($last < $first) {
			throw new ValidationException('The end date is before the start date');
		}
		$days = (int)$first->diff($last)->days + 1;
		if ($days > self::MAX_DAYS) {
			throw new ValidationException('Reports cover at most a year');
		}
		return [$zone, $first->getTimestamp(), $last->modify('+1 day')->getTimestamp(), $days];
	}

	/** @return int[]|null */
	private function teamIds(string $uid, ?int $teamId): ?array {
		$allowed = $this->teams->teamIdsFor($uid);
		if ($teamId === null) {
			return $allowed;
		}
		if ($allowed !== null && !in_array($teamId, $allowed, true)) {
			throw new ValidationException('Unknown brand');
		}
		return [$teamId];
	}

	/** Tickets created or resolved in the range. */
	private function load(?array $teamIds, int $start, int $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'team_id', 'status',
			'assignee_uid', 'created_at', 'first_response_at', 'resolved_at')
			->from('ticketsystem_tickets');
		if ($teamIds !== null) {
			if ($teamIds === []) {
				return [];
			}
			$qb->andWhere($qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		$between = fn (string $column) => $qb->expr()->andX(
			$qb->expr()->gte($column, $qb->createNamedParameter($start, IQueryBuilder::PARAM_INT)),
			$qb->expr()->lt($column, $qb->createNamedParameter($end, IQueryBuilder::PARAM_INT)),
		);
		$qb->andWhere($qb->expr()->orX($between('created_at'), $between('resolved_at')));
		$result = $qb->executeQuery();
		$rows = [];
		$ints = ['id', 'team_id', 'created_at', 'first_response_at', 'resolved_at'];
		while ($row = $result->fetch()) {
			foreach ($ints as $column) {
				$row[$column] = $row[$column] !== null ? (int)$row[$column] : null;
			}
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	/** @return array{total: int, unassigned: int} */
	private function countOpen(?array $teamIds): array {
		$open = $this->settings->getOpenStatusKeys();
		$count = function (?callable $extra) use ($teamIds, $open): int {
			if ($open === [] || $teamIds === []) {
				return 0;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from('ticketsystem_tickets')
				->where($qb->expr()->in('status', $qb->createNamedParameter($open, IQueryBuilder::PARAM_STR_ARRAY)));
			if ($teamIds !== null) {
				$qb->andWhere($qb->expr()->in('team_id', $qb->createNamedParameter($teamIds, IQueryBuilder::PARAM_INT_ARRAY)));
			}
			if ($extra !== null) {
				$extra($qb);
			}
			$result = $qb->executeQuery();
			$n = (int)$result->fetchOne();
			$result->closeCursor();
			return $n;
		};
		return [
			'total' => $count(null),
			'unassigned' => $count(fn (IQueryBuilder $qb) => $qb->andWhere($qb->expr()->isNull('assignee_uid'))),
		];
	}

	private function byAgent(array $created, array $resolved): array {
		$agents = [];
		$row = function (?string $uid) use (&$agents) {
			$key = $uid ?? '';
			return $agents[$key] ??= ['uid' => $uid, 'resolved' => 0, 'resolutionTimes' => [], 'firstResponseTimes' => []];
		};
		foreach ($resolved as $r) {
			$entry = $row($r['assignee_uid']);
			$entry['resolved']++;
			$entry['resolutionTimes'][] = max(0, $r['resolved_at'] - $r['created_at']);
			$agents[$r['assignee_uid'] ?? ''] = $entry;
		}
		foreach ($created as $r) {
			if ($r['first_response_at'] !== null) {
				$entry = $row($r['assignee_uid']);
				$entry['firstResponseTimes'][] = $r['first_response_at'] - $r['created_at'];
				$agents[$r['assignee_uid'] ?? ''] = $entry;
			}
		}
		$result = array_map(fn ($a) => [
			'uid' => $a['uid'],
			'resolved' => $a['resolved'],
			'medianResolution' => $this->median($a['resolutionTimes']),
			'medianFirstResponse' => $this->median($a['firstResponseTimes']),
		], array_values($agents));
		usort($result, fn ($a, $b) => $b['resolved'] <=> $a['resolved']);
		return $result;
	}

	/** @param int[] $values */
	private function median(array $values): ?int {
		if ($values === []) {
			return null;
		}
		sort($values);
		$middle = intdiv(count($values), 2);
		return count($values) % 2 ? $values[$middle] : intdiv($values[$middle - 1] + $values[$middle], 2);
	}

	/** @param int[] $values */
	private function average(array $values): ?int {
		return $values === [] ? null : (int)round(array_sum($values) / count($values));
	}
}
