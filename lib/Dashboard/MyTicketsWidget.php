<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Dashboard;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\SettingsService;
use OCA\TicketSystem\Service\TicketService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/** "My open tickets" on the Nextcloud Dashboard, for agents only. */
class MyTicketsWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IConditionalWidget, IReloadableWidget {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
		private IUserSession $userSession,
		private AgentService $agents,
		private TicketService $tickets,
		private SettingsService $settings,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-my-tickets';
	}

	public function getTitle(): string {
		return $this->l->t('My open tickets');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-ticketsystem';
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('ticketsystem.page.index');
	}

	public function load(): void {
	}

	public function isEnabled(): bool {
		return $this->agents->isAgent($this->userSession->getUser()?->getUID());
	}

	public function getReloadInterval(): int {
		return 120;
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		if (!$this->agents->isAgent($userId)) {
			return new WidgetItems();
		}
		$items = [];
		foreach ($this->tickets->listView($userId, 'mine', null, $limit) as $ticket) {
			$customer = $ticket->getCustomerName() ?? $ticket->getCustomerEmail() ?? '';
			$items[] = new WidgetItem(
				$ticket->getReference() . ' ' . $ticket->getSubject(),
				trim($customer . ' · ' . $this->settings->getStatusLabel($ticket->getStatus()), ' ·'),
				$this->url->linkToRouteAbsolute('ticketsystem.page.ticket', ['id' => $ticket->getId()]),
				$this->getIconUrl(),
				(string)$ticket->getUpdatedAt(),
			);
		}
		return new WidgetItems($items, $this->l->t('No open tickets assigned to you'));
	}

	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(WidgetButton::TYPE_MORE, $this->url->linkToRouteAbsolute('ticketsystem.page.index'), $this->l->t('All tickets')),
		];
	}
}
