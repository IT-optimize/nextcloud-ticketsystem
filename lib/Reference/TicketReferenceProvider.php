<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Reference;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\SettingsService;
use OCA\TicketSystem\Service\TicketService;
use OCP\Collaboration\Reference\ADiscoverableReferenceProvider;
use OCP\Collaboration\Reference\IReference;
use OCP\Collaboration\Reference\ISearchableReferenceProvider;
use OCP\Collaboration\Reference\Reference;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Ticket links pasted in Talk, Text, Deck or Mail show as a card with the
 * ticket's status, priority and assignee, for agents who may see the ticket.
 * Tickets can also be picked in the Smart Picker.
 */
class TicketReferenceProvider extends ADiscoverableReferenceProvider implements ISearchableReferenceProvider {
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
		return Application::APP_ID . '-ticket';
	}

	public function getTitle(): string {
		return $this->l->t('Tickets');
	}

	public function getOrder(): int {
		return 40;
	}

	public function getIconUrl(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function getSupportedSearchProviderIds(): array {
		return [Application::APP_ID];
	}

	public function matchReference(string $referenceText): bool {
		return $this->ticketId($referenceText) !== null;
	}

	public function resolveReference(string $referenceText): ?IReference {
		$id = $this->ticketId($referenceText);
		$uid = $this->userSession->getUser()?->getUID();
		if ($id === null || $uid === null || !$this->agents->isAgent($uid)) {
			return null;
		}
		try {
			$ticket = $this->tickets->getForAgent($uid, $id);
		} catch (NotFoundException) {
			return null;
		}
		$parts = [$this->settings->getStatusLabel($ticket->getStatus())];
		foreach ($this->settings->getPriorities() as $priority) {
			if ($priority['key'] === $ticket->getPriority()) {
				$parts[] = $priority['label'];
			}
		}
		$parts[] = $ticket->getAssigneeUid() !== null
			? $this->l->t('Assigned to %s', [$this->agents->getDisplayName($ticket->getAssigneeUid())])
			: $this->l->t('Unassigned');
		$customer = $ticket->getCustomerName() ?? $ticket->getCustomerEmail();
		if ($customer !== null) {
			$parts[] = $customer;
		}

		$reference = new Reference($referenceText);
		$reference->setTitle($ticket->getReference() . ' ' . $ticket->getSubject());
		$reference->setDescription(implode(' · ', $parts));
		$reference->setImageUrl($this->getIconUrl());
		$reference->setUrl($this->url->linkToRouteAbsolute('ticketsystem.page.ticket', ['id' => $ticket->getId()]));
		return $reference;
	}

	public function getCachePrefix(string $referenceId): string {
		return (string)($this->ticketId($referenceId) ?? '');
	}

	/** Cached per user: who may see a ticket depends on their brands. */
	public function getCacheKey(string $referenceId): ?string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	private function ticketId(string $text): ?int {
		$base = preg_quote(rtrim($this->url->getAbsoluteURL('/'), '/'), '/');
		if (preg_match('/^' . $base . '\/(?:index\.php\/)?apps\/ticketsystem\/tickets\/(\d+)(?:[?#].*)?$/', $text, $m)) {
			return (int)$m[1];
		}
		return null;
	}
}
