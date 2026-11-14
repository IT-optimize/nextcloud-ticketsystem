<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

/** "Ticket system" in the Activity settings: stream on, email digest off by default. */
class Setting extends ActivitySettings {
	public const TYPE = 'ticketsystem';

	public function __construct(
		private IL10N $l,
	) {
	}

	public function getIdentifier(): string {
		return self::TYPE;
	}

	public function getName(): string {
		return $this->l->t('Changes to <strong>ticketsystem tickets</strong> you work on');
	}

	public function getGroupIdentifier(): string {
		return 'ticketsystem';
	}

	public function getGroupName(): string {
		return $this->l->t('Ticket system');
	}

	public function getPriority(): int {
		return 50;
	}

	public function canChangeStream(): bool {
		return true;
	}

	public function isDefaultEnabledStream(): bool {
		return true;
	}

	public function canChangeMail(): bool {
		return true;
	}

	public function isDefaultEnabledMail(): bool {
		return false;
	}
}
