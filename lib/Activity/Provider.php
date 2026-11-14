<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Activity;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Service\SettingsService;
use OCA\TicketSystem\Service\TeamService;
use OCA\TicketSystem\Service\TicketService;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IProvider;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;

/** Turns Ticket system activities into readable sentences. */
class Provider implements IProvider {
	public function __construct(
		private IFactory $l10nFactory,
		private IURLGenerator $url,
		private IUserManager $userManager,
		private SettingsService $settings,
		private TicketService $tickets,
		private TeamService $teams,
	) {
	}

	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}
		$l = $this->l10nFactory->get(Application::APP_ID, $language);
		$p = $event->getSubjectParameters();
		$ticketId = (int)($p['ticketId'] ?? $event->getObjectId());

		// Agents who no longer work on the ticket's brand see nothing about it.
		$subjectText = (string)($p['subject'] ?? '');
		try {
			$ticket = $this->tickets->get($ticketId);
			if (!$this->teams->canAccess($event->getAffectedUser(), $ticket->getTeamId())) {
				throw new UnknownActivityException();
			}
			$subjectText = $ticket->getSubject();
		} catch (NotFoundException) {
			// Entries from before 0.8.0 stored the subject; newer ones show only the number.
		}

		$params = [
			'ticket' => [
				'type' => 'highlight',
				'id' => (string)$ticketId,
				'name' => '#' . $ticketId . ' ' . $subjectText,
				'link' => $this->url->linkToRouteAbsolute('ticketsystem.page.ticket', ['id' => $ticketId]),
			],
		];
		$user = fn (?string $uid) => $uid ? ['type' => 'user', 'id' => $uid, 'name' => $this->userManager->getDisplayName($uid) ?? $uid] : null;
		$actor = $user($p['actor'] ?? null);
		if ($actor !== null) {
			$params['actor'] = $actor;
		}
		$you = ($p['actor'] ?? null) === $event->getAffectedUser();

		switch ($event->getSubject()) {
			case 'created':
				$text = $actor === null ? $l->t('New ticket {ticket}') : ($you ? $l->t('You created {ticket}') : $l->t('{actor} created {ticket}'));
				break;
			case 'assigned':
				if (empty($p['assignee'])) {
					$text = $actor === null ? $l->t('{ticket} was unassigned') : ($you ? $l->t('You unassigned {ticket}') : $l->t('{actor} unassigned {ticket}'));
				} elseif ($p['assignee'] === $event->getAffectedUser()) {
					$text = $you ? $l->t('You took {ticket}') : ($actor === null ? $l->t('{ticket} was assigned to you') : $l->t('{actor} assigned {ticket} to you'));
				} else {
					$params['assignee'] = $user($p['assignee']);
					$text = $actor === null ? $l->t('{ticket} was assigned to {assignee}') : ($you ? $l->t('You assigned {ticket} to {assignee}') : $l->t('{actor} assigned {ticket} to {assignee}'));
				}
				break;
			case 'status':
				$to = $this->settings->getStatusLabel((string)($p['to'] ?? ''));
				$text = $actor === null
					? str_replace('{status}', $to, $l->t('{ticket} is now {status}'))
					: str_replace('{status}', $to, $you ? $l->t('You set {ticket} to {status}') : $l->t('{actor} set {ticket} to {status}'));
				break;
			case 'customer_reply':
				$text = $l->t('The customer replied on {ticket}');
				break;
			case 'agent_reply':
				$text = $you ? $l->t('You replied to the customer on {ticket}') : $l->t('{actor} replied to the customer on {ticket}');
				break;
			case 'agent_note':
				$text = $you ? $l->t('You added a note on {ticket}') : $l->t('{actor} added a note on {ticket}');
				break;
			default:
				throw new UnknownActivityException();
		}
		if ($actor === null) {
			unset($params['actor']);
		}

		$event->setRichSubject($text, $params);
		$event->setParsedSubject(str_replace(
			array_map(fn ($k) => '{' . $k . '}', array_keys($params)),
			array_map(fn ($v) => $v['name'] ?? '', array_values($params)),
			$text,
		));
		$event->setLink($params['ticket']['link']);
		$event->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
		return $event;
	}
}
