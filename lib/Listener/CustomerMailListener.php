<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Listener;

use OCA\TicketSystem\Event\MessageAddedEvent;
use OCA\TicketSystem\Event\TicketCreatedEvent;
use OCA\TicketSystem\Event\TicketUpdatedEvent;
use OCA\TicketSystem\Service\CustomerMailer;
use OCA\TicketSystem\Service\SettingsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Emails the customer: public agent replies, a confirmation when a new
 * ticket arrives by email. Internal notes are never sent (CustomerMailer checks
 * visibility first).
 *
 * @template-implements IEventListener<Event>
 */
class CustomerMailListener implements IEventListener {
	public function __construct(
		private CustomerMailer $mailer,
		private SettingsService $settings,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof MessageAddedEvent) {
			// Updates the message in place with its delivery status, so the
			// API response that follows already shows sent / failed.
			$this->mailer->sendReply($event->getTicket(), $event->getMessage());
		} elseif ($event instanceof TicketCreatedEvent && in_array($event->getTicket()->getChannel(), ['email', 'web'], true)) {
			$this->mailer->sendAcknowledgment($event->getTicket());
		}
	}
}
