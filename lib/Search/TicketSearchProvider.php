<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Search;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\SettingsService;
use OCA\TicketSystem\Service\TicketService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/** Tickets in Nextcloud's unified search, for agents only. */
class TicketSearchProvider implements IProvider {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
		private AgentService $agents,
		private TicketService $tickets,
		private SettingsService $settings,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Tickets');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 40;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->agents->isAgent($user->getUID())) {
			return SearchResult::complete($this->getName(), []);
		}
		$offset = (int)($query->getCursor() ?? 0);
		$limit = $query->getLimit();
		$tickets = $this->tickets->listView($user->getUID(), 'all', $query->getTerm(), $limit, $offset);
		$icon = $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));

		$entries = array_map(fn ($ticket) => new SearchResultEntry(
			$icon,
			$ticket->getReference() . ' ' . $ticket->getSubject(),
			trim(($ticket->getCustomerName() ?? $ticket->getCustomerEmail() ?? '') . ' · ' . $this->settings->getStatusLabel($ticket->getStatus()), ' ·'),
			$this->url->linkToRouteAbsolute('ticketsystem.page.ticket', ['id' => $ticket->getId()]),
			'',
			false,
		), $tickets);

		return SearchResult::paginated($this->getName(), $entries, $offset + count($entries));
	}
}
