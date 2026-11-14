<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Activity;

use OCA\TicketSystem\AppInfo\Application;
use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

/** "Ticket system" in the Activity app's sidebar. */
class Filter implements IFilter {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $url,
	) {
	}

	public function getIdentifier(): string {
		return 'ticketsystem';
	}

	public function getName(): string {
		return $this->l->t('Ticket system');
	}

	public function getPriority(): int {
		return 40;
	}

	public function getIcon(): string {
		return $this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg'));
	}

	public function filterTypes(array $types): array {
		return array_values(array_intersect([Setting::TYPE], $types));
	}

	public function allowedApps(): array {
		return [Application::APP_ID];
	}
}
