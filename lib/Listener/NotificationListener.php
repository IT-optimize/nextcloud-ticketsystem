<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Listener;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Event\MessageAddedEvent;
use OCA\TicketSystem\Event\TicketAssignedEvent;
use OCA\TicketSystem\Event\TicketUpdatedEvent;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Notifies agents in Nextcloud: the assignee (or, when nobody is assigned, the brand's agents) about customer
 * replies and colleagues' messages, anyone @mentioned in a message, and the
 * assignee about assignments. Built on Ticket system's own events, the
 * same way an add-in app would hook in.
 *
 * @template-implements IEventListener<Event>
 */
class NotificationListener implements IEventListener {
	public function __construct(
		private IManager $notifications,
		private ITimeFactory $time,
		private AgentService $agents,
		private TicketService $tickets,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof TicketAssignedEvent) {
				$this->onAssigned($event);
			} elseif ($event instanceof MessageAddedEvent) {
				$this->onMessage($event);
			}
		} catch (\Throwable $e) {
			// A failed notification must never break the ticket action itself.
			$this->logger->error('Ticket system notification failed', ['exception' => $e]);
		}
	}

	/** Agents @mentioned in a text who work on the ticket's brand. */
	public function mentionedIn(string $body, Ticket $ticket): array {
		$uids = [];
		foreach ($this->agents->listAgents() as $agent) {
			$uid = $agent['uid'];
			if (preg_match('/(?<![\w.@-])@' . preg_quote($uid, '/') . '(?![\w@-]|\.\w)/u', $body)
				&& $this->agents->worksOn($uid, $ticket->getTeamId())) {
				$uids[] = $uid;
			}
		}
		return $uids;
	}

	private function onAssigned(TicketAssignedEvent $event): void {
		$ticket = $event->getTicket();

		// Clear the old assignee's pending "assigned to you" notification.
		$previous = $event->getPreviousAssigneeUid();
		if ($previous !== null) {
			$old = $this->notifications->createNotification();
			$old->setApp(Application::APP_ID)
				->setUser($previous)
				->setObject('ticket', (string)$ticket->getId())
				->setSubject('assigned');
			$this->notifications->markProcessed($old);
		}

		$assignee = $event->getAssigneeUid();
		if ($assignee === null || $assignee === $event->getActorUid()) {
			return;
		}
		$this->notify($assignee, $ticket, 'assigned', ['actor' => $event->getActorUid()]);
	}

	private function onMessage(MessageAddedEvent $event): void {
		$message = $event->getMessage();
		$ticket = $event->getTicket();
		$actor = $event->getActorUid();
		if ($message->getAuthorType() === Message::AUTHOR_SYSTEM) {
			return;
		}

		$mentioned = [];
		if ($message->getAuthorType() === Message::AUTHOR_AGENT) {
			$mentioned = array_values(array_diff($this->mentionedIn($message->getBody(), $ticket), [$actor]));
			foreach ($mentioned as $uid) {
				$this->notify($uid, $ticket, 'mentioned', [
					'actor' => $actor,
					'messageId' => $message->getId(),
					'excerpt' => $this->excerpt($message->getBody()),
				]);
			}
		}

		// Customer replies reach the brand's agents when nobody is assigned;
		// colleagues' replies and notes only the assignee.
		$recipients = $message->getAuthorType() === Message::AUTHOR_CUSTOMER
			? $this->tickets->responsibleFor($ticket)
			: array_filter([$ticket->getAssigneeUid()]);
		$recipients = array_diff(array_unique($recipients), $mentioned, [$actor]);

		foreach ($recipients as $uid) {
			if (!$this->agents->worksOn($uid, $ticket->getTeamId())) {
				continue;
			}
			if ($message->getAuthorType() === Message::AUTHOR_CUSTOMER) {
				$this->notify($uid, $ticket, 'customer_reply', ['messageId' => $message->getId()]);
			} else {
				$this->notify($uid, $ticket, $message->isPublic() ? 'agent_reply' : 'agent_note', [
					'actor' => $actor,
					'messageId' => $message->getId(),
					'excerpt' => $this->excerpt($message->getBody()),
				]);
			}
		}
	}

	private function notify(string $uid, Ticket $ticket, string $subject, array $params): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($uid)
			->setDateTime($this->time->getDateTime())
			->setObject('ticket', (string)$ticket->getId())
			->setSubject($subject, ['ticketId' => $ticket->getId()] + $params);
		$this->notifications->notify($notification);
	}

	private function excerpt(string $body): string {
		$text = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
		return mb_strlen($text) > 120 ? mb_substr($text, 0, 119) . '…' : $text;
	}
}
