<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\BackgroundJob;

use OCA\TicketSystem\Service\PrivacyService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Erases the customer data of tickets done longer ago than the settings allow. */
class RetentionJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PrivacyService $privacy,
	) {
		parent::__construct($time);
		$this->setInterval(86400);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->privacy->eraseExpired();
	}
}
