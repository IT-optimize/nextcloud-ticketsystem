<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\BackgroundJob;

use OCA\TicketSystem\Service\MailIntakeService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/**
 * Checks the support inbox. Runs as often as Nextcloud's cron allows, at most
 * once a minute; errors are recorded on the settings page by the service.
 */
class FetchMailJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private MailIntakeService $intake,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		// Errors are recorded per inbox and shown in the settings; the next run tries again.
		$this->intake->run();
	}
}
