<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Activity;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Event\MessageAddedEvent;
use OCA\TicketSystem\Event\TicketAssignedEvent;
use OCA\TicketSystem\Event\TicketCreatedEvent;
use OCA\TicketSystem\Event\TicketUpdatedEvent;
use OCA\TicketSystem\Service\TicketService;
use OCP\Activity\IManager;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Ticket changes in Nextcloud's Activity stream (and its email digests),
 * for the assignee and whoever made the change.
 *
 * @template-implements IEventListener<Event>
 */
class ActivityListener implements IEventListener {
	public function __construct(
		private IManager $activity,
		private TicketService $tickets,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof TicketCreatedEvent) {
				$this->publish($event->getTicket(), $event->getActorUid(), 'created', []);
			} elseif ($event instanceof TicketAssignedEvent) {
				$this->publish($event->getTicket(), $event->getActorUid(), 'assigned', ['assignee' => $event->getAssigneeUid()],
					[$event->getPreviousAssigneeUid()]);
			} elseif ($event instanceof TicketUpdatedEvent) {
				if ($event->hasChanged('status')) {
					$change = $event->getChanges()['status'];
					$this->publish($event->getTicket(), $event->getActorUid(), 'status', ['from' => $change['old'], 'to' => $change['new']]);
				}
			} elseif ($event instanceof MessageAddedEvent) {
				$message = $event->getMessage();
				$subject = match (true) {
					$message->getAuthorType() === Message::AUTHOR_CUSTOMER => 'customer_reply',
					$message->getAuthorType() === Message::AUTHOR_AGENT && $message->isPublic() => 'agent_reply',
					$message->getAuthorType() === Message::AUTHOR_AGENT => 'agent_note',
					default => null,
				};
				if ($subject !== null) {
					$this->publish($event->getTicket(), $event->getActorUid(), $subject, []);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->error('Ticket system could not publish an activity', ['exception' => $e]);
		}
	}

	/** @param array<string, mixed> $params */
	private function publish(Ticket $ticket, ?string $actor, string $subject, array $params, array $alsoNotify = []): void {
		$users = array_merge(
			[$actor, $ticket->getAssigneeUid()],
			$alsoNotify,
		);
		// No subject or other customer text: Activity keeps entries for a year and
		// they can't be deleted, so erased customer data must not live on there.
		// The Provider shows the ticket's current subject instead.
		$params = ['ticketId' => $ticket->getId(), 'actor' => $actor] + $params;
		foreach (array_unique(array_filter($users)) as $uid) {
			$event = $this->activity->generateEvent();
			$event->setApp(Application::APP_ID)
				->setType(Setting::TYPE)
				->setAffectedUser($uid)
				->setAuthor($actor ?? '')
				->setObject('ticketsystem_ticket', $ticket->getId(), $ticket->getReference())
				->setSubject($subject, $params);
			$this->activity->publish($event);
		}
	}
}
