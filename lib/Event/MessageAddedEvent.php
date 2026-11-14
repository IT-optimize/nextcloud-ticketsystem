<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Event;

use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\Ticket;

/**
 * Dispatched after a message was added to a ticket.
 * Check $message->isPublic() before exposing anything outside the team.
 */
class MessageAddedEvent extends AbstractTicketEvent {
	public function __construct(
		Ticket $ticket,
		?string $actorUid,
		private Message $message,
	) {
		parent::__construct($ticket, $actorUid);
	}

	public function getMessage(): Message {
		return $this->message;
	}


	/** Includes internal notes: point webhooks only at systems the team trusts. */
	protected function getWebhookExtra(): array {
		return ['message' => $this->message->jsonSerialize()];
	}
}
