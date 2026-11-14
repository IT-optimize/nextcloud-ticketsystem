<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\BackgroundJob;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\TicketService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;

/**
 * Brings snoozed tickets back when their time is up and tells the assignee
 * (or the brand's agents, when nobody is assigned).
 */
class WakeSnoozedJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private TicketService $tickets,
		private IManager $notifications,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(IJob::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		foreach ($this->tickets->wakeSnoozed() as $ticket) {
			try {
				foreach ($this->tickets->responsibleFor($ticket) as $uid) {
					$notification = $this->notifications->createNotification();
					$notification->setApp(Application::APP_ID)
						->setUser($uid)
						->setDateTime($this->time->getDateTime())
						->setObject('ticket', (string)$ticket->getId())
						->setSubject('snooze_ended', ['ticketId' => $ticket->getId()]);
					$this->notifications->notify($notification);
				}
			} catch (\Throwable $e) {
				$this->logger->error('Ticket system could not notify about a woken ticket', ['exception' => $e]);
			}
		}
	}
}
