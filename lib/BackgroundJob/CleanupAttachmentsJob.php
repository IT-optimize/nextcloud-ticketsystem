<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\BackgroundJob;

use OCA\TicketSystem\Service\AttachmentService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;

/** Deletes files agents uploaded but never sent (the reply was abandoned). */
class CleanupAttachmentsJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AttachmentService $attachments,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 3600);
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$this->attachments->deleteAbandoned();
	}
}
