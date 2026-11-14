<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\BackgroundJob;

use OCA\TicketSystem\Service\ScheduleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/** Sends scheduled replies whose time has come, on every cron run. */
class SendScheduledJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ScheduleService $schedule,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(60);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		try {
			$this->schedule->sendDue();
		} catch (\Throwable $e) {
			$this->logger->error('Ticket system could not send scheduled replies', ['exception' => $e]);
		}
	}
}
