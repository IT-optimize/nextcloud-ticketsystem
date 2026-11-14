<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Notification;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Service\TeamService;
use OCA\TicketSystem\Service\TicketService;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
		private IUserManager $userManager,
		private TicketService $tickets,
		private TeamService $teams,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l10nFactory->get(Application::APP_ID)->t('Ticket system');
	}

	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
		$params = $notification->getSubjectParameters();

		try {
			$ticket = $this->tickets->get((int)($params['ticketId'] ?? $notification->getObjectId()));
		} catch (NotFoundException) {
			throw new AlreadyProcessedException();
		}
		// Reassigned since, or moved to a brand this agent doesn't work on: stale.
		if (($notification->getSubject() === 'assigned' && $ticket->getAssigneeUid() !== $notification->getUser())
			|| !$this->teams->canAccess($notification->getUser(), $ticket->getTeamId())) {
			throw new AlreadyProcessedException();
		}
		$actor = $params['actor'] ?? null;
		$actorParam = $actor !== null ? [
			'type' => 'user',
			'id' => $actor,
			'name' => $this->userManager->getDisplayName($actor) ?? $actor,
		] : null;

		$ticketParam = [
			'type' => 'highlight',
			'id' => (string)$ticket->getId(),
			'name' => $ticket->getReference() . ' ' . $ticket->getSubject(),
			'link' => $this->url->linkToRouteAbsolute('ticketsystem.page.ticket', ['id' => $ticket->getId()]),
		];

		switch ($notification->getSubject()) {
			case 'assigned':
				if ($actorParam !== null) {
					$notification->setRichSubject($l->t('{actor} assigned {ticket} to you'), [
						'actor' => $actorParam,
						'ticket' => $ticketParam,
					]);
				} else {
					$notification->setRichSubject($l->t('{ticket} was assigned to you'), ['ticket' => $ticketParam]);
				}
				break;
			case 'customer_reply':
				$notification->setRichSubject($l->t('The customer replied on {ticket}'), ['ticket' => $ticketParam]);
				break;
			case 'mentioned':
			case 'agent_reply':
			case 'agent_note':
				if ($actorParam === null) {
					throw new AlreadyProcessedException();
				}
				$subjects = [
					'mentioned' => $l->t('{actor} mentioned you on {ticket}'),
					'agent_reply' => $l->t('{actor} replied to the customer on {ticket}'),
					'agent_note' => $l->t('{actor} added a note on {ticket}'),
				];
				$notification->setRichSubject($subjects[$notification->getSubject()], ['actor' => $actorParam, 'ticket' => $ticketParam]);
				if (($params['excerpt'] ?? '') !== '') {
					$notification->setParsedMessage((string)$params['excerpt']);
				}
				break;
			case 'delivery_failed':
				$notification->setRichSubject($l->t('The email to the customer on {ticket} could not be delivered'), ['ticket' => $ticketParam]);
				if (($params['reason'] ?? '') !== '') {
					$notification->setParsedMessage((string)$params['reason']);
				}
				break;
			case 'snooze_ended':
				$notification->setRichSubject($l->t('{ticket} is back: the snooze ended'), ['ticket' => $ticketParam]);
				break;
			case 'scheduled_failed':
				$notification->setRichSubject($l->t('Your scheduled reply on {ticket} could not be sent'), ['ticket' => $ticketParam]);
				break;
			case 'scheduled_sent':
				$notification->setRichSubject($l->t('Your scheduled reply on {ticket} was sent'), ['ticket' => $ticketParam]);
				break;
			default:
				throw new UnknownNotificationException();
		}

		$notification->setLink($ticketParam['link'])
			->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $notification;
	}
}
