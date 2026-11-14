<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Listener;

use OCA\TicketSystem\Event\TicketUpdatedEvent;
use OCA\TicketSystem\Service\TicketService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Writes "who changed what" into the ticket's thread, so agents can see when
 * a ticket moved, who took it and how the customer rated it.
 *
 * @template-implements IEventListener<Event>
 */
class HistoryListener implements IEventListener {
	/** Fields worth a history line; the UI turns each into a sentence. */
	private const FIELDS = ['status', 'priority', 'assigneeUid', 'subject', 'customerEmail', 'customerName', 'cc', 'teamId', 'snoozedUntil', 'tagIds', 'companyId'];

	public function __construct(
		private TicketService $tickets,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof TicketUpdatedEvent) {
			return;
		}
		try {
			$ticket = $event->getTicket();
			$changes = array_filter($event->getChanges(),
				fn (string $field) => in_array($field, self::FIELDS, true),
				ARRAY_FILTER_USE_KEY);
			if ($changes === []) {
				return;
			}
			$text = implode('; ', array_map(
				fn (string $field, array $c) => sprintf('%s: %s → %s', $field, $this->plain($c['old']), $this->plain($c['new'])),
				array_keys($changes), $changes,
			));
			$meta = ['type' => 'change', 'changes' => $changes];
			$this->tickets->addHistoryEntry($ticket, $event->getActorUid(), $meta, $text);
		} catch (\Throwable $e) {
			$this->logger->error('Ticket system could not record ticket history', ['exception' => $e]);
		}
	}

	private function plain(mixed $value): string {
		if ($value === null || $value === []) {
			return '–';
		}
		return is_array($value) ? implode(', ', $value) : (string)$value;
	}
}
