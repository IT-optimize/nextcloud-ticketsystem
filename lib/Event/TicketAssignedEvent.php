<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Event;

use OCA\TicketSystem\Db\Ticket;

/** Dispatched when the assignee changes (in addition to TicketUpdatedEvent). */
class TicketAssignedEvent extends AbstractTicketEvent {
	public function __construct(
		Ticket $ticket,
		?string $actorUid,
		private ?string $previousAssigneeUid,
	) {
		parent::__construct($ticket, $actorUid);
	}

	public function getPreviousAssigneeUid(): ?string {
		return $this->previousAssigneeUid;
	}

	public function getAssigneeUid(): ?string {
		return $this->getTicket()->getAssigneeUid();
	}


	protected function getWebhookExtra(): array {
		return ['previousAssigneeUid' => $this->previousAssigneeUid];
	}
}
