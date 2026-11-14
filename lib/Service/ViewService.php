<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\TicketFilter;
use OCA\TicketSystem\Db\TicketMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;

/**
 * Ticket list views. Each has a filter, a small JSON object:
 *
 *   scope       open | closed | any      which statuses (ignored when "statuses" is set)
 *   statuses    [status keys]
 *   assignee    null | "me" | "none" | user id
 *   teamIds, tagIds, priorities   any of these (empty = all)
 *   needsReply  true = only tickets whose customer waits for an answer
 *   snoozed     hide | only | any        default: hide for open lists, any otherwise
 */
class ViewService {
	/** The status that means "waiting for the customer". */
	public const WAITING_STATUS = 'pending';

	public const BUILT_IN = ['mine', 'needs-reply', 'unassigned', 'waiting', 'open', 'snoozed', 'closed', 'all'];

	public function __construct(
		private SettingsService $settings,
		private TeamService $teams,
		private ITimeFactory $time,
		private IL10N $l,
	) {
	}

	/** @return list<array{id: string, name: string, builtIn: bool, shared: bool, filter: array}> */
	public function listFor(string $uid): array {
		$views = [];
		foreach ($this->builtInSpecs() as $id => [$name, $spec]) {
			$views[] = ['id' => $id, 'name' => $name, 'builtIn' => true, 'shared' => true, 'filter' => $this->normalize($spec)];
		}
		return $views;
	}

	/**
	 * The filter of a built-in view.
	 *
	 * @throws NotFoundException
	 */
	public function specFor(string $uid, string $viewId): array {
		$builtIn = $this->builtInSpecs();
		if (isset($builtIn[$viewId])) {
			return $this->normalize($builtIn[$viewId][1]);
		}
		throw new NotFoundException('View not found');
	}

	/** Turns a filter into a query, limited to the brands this agent works on. */
	public function buildFilter(string $uid, array $spec, ?string $query = null): TicketFilter {
		$spec = $this->normalize($spec);
		$filter = new TicketFilter();
		$filter->now = $this->time->getTime();
		$filter->query = $query;

		if ($spec['statuses'] !== []) {
			$filter->statuses = $spec['statuses'];
		} elseif ($spec['scope'] === 'open') {
			$filter->statuses = $this->settings->getOpenStatusKeys();
		} elseif ($spec['scope'] === 'active') {
			// Open tickets that are not waiting for the customer: the team's work.
			$filter->statuses = array_values(array_diff($this->settings->getOpenStatusKeys(), [self::WAITING_STATUS])) ?: $this->settings->getOpenStatusKeys();
		} elseif ($spec['scope'] === 'closed') {
			$filter->statuses = $this->settings->getClosedStatusKeys();
		}

		$filter->assignee = match ($spec['assignee']) {
			null => null,
			'me' => $uid,
			'none' => TicketMapper::ASSIGNEE_NONE,
			default => $spec['assignee'],
		};

		$allowed = $this->teams->teamIdsFor($uid);
		if ($spec['teamIds'] === []) {
			$filter->teamIds = $allowed;
		} else {
			$filter->teamIds = $allowed === null
				? $spec['teamIds']
				: array_values(array_intersect($spec['teamIds'], $allowed));
		}

		$filter->tagIds = $spec['tagIds'];
		$filter->priorities = $spec['priorities'];
		$filter->needsReply = $spec['needsReply'];
		$filter->snoozed = $spec['snoozed'] ?? ($spec['scope'] === 'open' && $spec['statuses'] === []
			? TicketFilter::SNOOZED_HIDE : TicketFilter::SNOOZED_ANY);
		$filter->sort = $spec['sort'];
		return $filter;
	}

	/**
	 * A clean filter: unknown keys dropped, values of the right type, so a
	 * stored view keeps working after statuses or tags change.
	 */
	public function normalize(array $spec): array {
		$strings = fn ($v) => array_values(array_unique(array_filter(array_map('strval', is_array($v) ? $v : []), fn ($s) => $s !== '')));
		$ints = fn ($v) => array_values(array_unique(array_filter(array_map('intval', is_array($v) ? $v : []), fn ($i) => $i > 0)));
		$assignee = $spec['assignee'] ?? null;
		$assignee = is_string($assignee) && $assignee !== '' ? mb_substr($assignee, 0, 64) : null;
		$snoozed = $spec['snoozed'] ?? null;
		return [
			'scope' => in_array($spec['scope'] ?? null, ['open', 'active', 'closed', 'any'], true) ? $spec['scope'] : 'open',
			'statuses' => $strings($spec['statuses'] ?? []),
			'assignee' => $assignee,
			'teamIds' => $ints($spec['teamIds'] ?? []),
			'tagIds' => $ints($spec['tagIds'] ?? []),
			'priorities' => $strings($spec['priorities'] ?? []),
			'needsReply' => !empty($spec['needsReply']),
			'snoozed' => in_array($snoozed, [TicketFilter::SNOOZED_HIDE, TicketFilter::SNOOZED_ONLY, TicketFilter::SNOOZED_ANY], true) ? $snoozed : null,
			'sort' => in_array($spec['sort'] ?? null, [TicketFilter::SORT_UPDATED, TicketFilter::SORT_CREATED, TicketFilter::SORT_WAITING], true)
				? $spec['sort'] : TicketFilter::SORT_UPDATED,
		];
	}

	/** @return array<string, array{0: string, 1: array}> */
	private function builtInSpecs(): array {
		// Tickets waiting for the customer leave the work views and wait in their
		// own; the customer's answer reopens them, which brings them back.
		$waiting = in_array(self::WAITING_STATUS, $this->settings->getStatusKeys(), true);
		return array_filter([
			'mine' => [$this->l->t('Assigned to me'), ['scope' => 'active', 'assignee' => 'me']],
			'needs-reply' => [$this->l->t('Needs reply'), ['scope' => 'active', 'needsReply' => true, 'sort' => 'waiting']],
			'unassigned' => [$this->l->t('Unassigned'), ['scope' => 'active', 'assignee' => 'none']],
			'waiting' => $waiting ? [$this->l->t('Waiting for customer'), ['statuses' => [self::WAITING_STATUS], 'sort' => 'updated']] : null,
			'open' => [$this->l->t('All open'), ['scope' => 'open']],
			'snoozed' => [$this->l->t('Snoozed'), ['scope' => 'open', 'snoozed' => 'only']],
			'closed' => [$this->l->t('Resolved & closed'), ['scope' => 'closed']],
			'all' => [$this->l->t('All tickets'), ['scope' => 'any', 'snoozed' => 'any']],
		]);
	}

}
