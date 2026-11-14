<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Event;

use OCA\TicketSystem\Db\Ticket;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IWebhookCompatibleEvent;

/**
 * Base class for every Ticket system event.
 *
 * These events are the public extension contract for add-in apps: listen to
 * them with IEventDispatcher / registerEventListener() and react, without
 * changing Ticket system itself. Treat the Ticket as read-only in listeners; change
 * tickets through OCA\TicketSystem\Service\TicketService so events fire again.
 *
 * They are also webhook-compatible: Nextcloud's webhook_listeners app can send
 * them as JSON to outside systems without writing an add-in.
 */
abstract class AbstractTicketEvent extends Event implements IWebhookCompatibleEvent {
	public function __construct(
		private Ticket $ticket,
		private ?string $actorUid,
	) {
		parent::__construct();
	}

	public function getTicket(): Ticket {
		return $this->ticket;
	}

	/** The Nextcloud user who caused the change, or null for system/customer actions. */
	public function getActorUid(): ?string {
		return $this->actorUid;
	}

	public function getWebhookSerializable(): array {
		return array_merge([
			'ticket' => $this->ticket->jsonSerialize(),
			'actorUid' => $this->actorUid,
		], $this->getWebhookExtra());
	}

	/** Event-specific fields for the webhook payload. */
	protected function getWebhookExtra(): array {
		return [];
	}
}
