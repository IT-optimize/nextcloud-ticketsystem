<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Event;

use OCA\TicketSystem\Db\Ticket;

/**
 * Dispatched after ticket fields changed (status, priority, assignee, subject …).
 * getChanges() returns ['field' => ['old' => …, 'new' => …]] for changed fields only.
 */
class TicketUpdatedEvent extends AbstractTicketEvent {
	/**
	 * @param array<string, array{old: mixed, new: mixed}> $changes
	 */
	public function __construct(
		Ticket $ticket,
		?string $actorUid,
		private array $changes,
	) {
		parent::__construct($ticket, $actorUid);
	}

	/** @return array<string, array{old: mixed, new: mixed}> */
	public function getChanges(): array {
		return $this->changes;
	}

	public function hasChanged(string $field): bool {
		return array_key_exists($field, $this->changes);
	}

	protected function getWebhookExtra(): array {
		return ['changes' => $this->changes];
	}
}
