<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Command;

use OCA\TicketSystem\Service\MailIntakeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** occ ticketsystem:fetch-mail: check the support inbox now. */
class FetchMail extends Command {
	public function __construct(
		private MailIntakeService $intake,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ticketsystem:fetch-mail')
			->setDescription('Import new emails from the support inbox into Ticket system')
			->addOption('force', null, InputOption::VALUE_NONE, 'Run even when email intake is turned off in the settings');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$result = $this->intake->run((bool)$input->getOption('force'));
		if ($result['skippedRun']) {
			$output->writeln('Email intake is turned off, or another run is in progress. Use --force to run anyway.');
			return 0;
		}
		foreach ($result['errors'] as $error) {
			$output->writeln('<error>' . $error . '</error>');
		}
		$output->writeln(sprintf(
			'New tickets: %d, replies added: %d, ignored (auto-replies, duplicates): %d, failed: %d',
			$result['created'], $result['replies'], $result['ignored'], $result['failed'],
		));
		return $result['failed'] > 0 || $result['errors'] !== [] ? 1 : 0;
	}
}
