<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Db;

/**
 * What a ticket list shows. Built by ViewService from a view or a filter the
 * agent picked; TicketMapper turns it into SQL.
 */
class TicketFilter {
	public const SNOOZED_HIDE = 'hide';
	public const SNOOZED_ONLY = 'only';
	public const SNOOZED_ANY = 'any';

	public const SORT_UPDATED = 'updated';
	public const SORT_CREATED = 'created';
	public const SORT_WAITING = 'waiting';

	/** @param string[]|null $statuses only these statuses (null = any) */
	public ?array $statuses = null;
	/** A user id, TicketMapper::ASSIGNEE_NONE, or null for any. */
	public ?string $assignee = null;
	public ?string $query = null;
	/** @var int[]|null brands the agent may see (null = all) */
	public ?array $teamIds = null;
	/** @var int[] any of these tags */
	public array $tagIds = [];
	/** @var string[] */
	public array $priorities = [];
	/** Only tickets whose customer is waiting for an answer. */
	public bool $needsReply = false;
	public string $snoozed = self::SNOOZED_HIDE;
	public string $sort = self::SORT_UPDATED;
	/** Current time, for snooze checks. */
	public int $now = 0;
}
