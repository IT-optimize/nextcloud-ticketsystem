<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\Team;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Db\TimeEntry;
use OCA\TicketSystem\Db\TimeEntryMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDateTimeZone;
use OCP\IGroupManager;

/**
 * Time registration on tickets, priced for invoicing.
 *
 * The price per hour is the ticket company's rate, else the brand's rate. It is
 * stored on each entry when the entry is made, so changing a rate later does not
 * change work already done.
 */
class TimeService {
	public const MAX_MINUTES = 24 * 60;

	public function __construct(
		private TimeEntryMapper $mapper,
		private TeamService $teams,
		private CompanyService $companies,
		private IGroupManager $groupManager,
		private ITimeFactory $time,
		private IDateTimeZone $timeZone,
	) {
	}

	/** @return TimeEntry[] */
	public function forTicket(int $ticketId): array {
		return $this->mapper->findForTicket($ticketId);
	}

	/** @return array<int, int> minutes per ticket */
	public function totalsFor(array $ticketIds): array {
		return $ticketIds === [] ? [] : $this->mapper->totalsFor($ticketIds);
	}

	/** @throws ValidationException */
	public function add(string $uid, Ticket $ticket, array $data): TimeEntry {
		$team = $this->teams->find($ticket->getTeamId());
		$entry = new TimeEntry();
		$entry->setTicketId($ticket->getId());
		$entry->setUid($uid);
		$entry->setBillable(true);
		$entry->setWorkDate($this->today());
		$this->apply($entry, $data);
		[$rate, $currency] = $this->rateFor($ticket, $team);
		$entry->setRate($rate);
		$entry->setCurrency($currency);
		$now = $this->time->getTime();
		$entry->setCreatedAt($now);
		$entry->setUpdatedAt($now);
		return $this->mapper->insert($entry);
	}

	/** @throws NotFoundException|ValidationException */
	public function update(string $uid, int $ticketId, int $entryId, array $data): TimeEntry {
		$entry = $this->getEditable($uid, $ticketId, $entryId);
		$this->apply($entry, $data);
		$entry->setUpdatedAt($this->time->getTime());
		return $this->mapper->update($entry);
	}

	/** @throws NotFoundException|ValidationException */
	public function delete(string $uid, int $ticketId, int $entryId): void {
		$this->mapper->delete($this->getEditable($uid, $ticketId, $entryId));
	}

	public function moveAll(int $fromTicketId, int $toTicketId): void {
		$this->mapper->moveTo($fromTicketId, $toTicketId);
	}

	/**
	 * Time in a period, for the overview and the invoice export.
	 *
	 * @param array{from: string, to: string, teamId?: ?int, companyId?: int|string|null, uid?: ?string, billable?: ?bool, invoiced?: ?bool} $filter
	 * @return list<array{entry: TimeEntry, teamId: int, companyId: ?int, subject: string, customerEmail: ?string}>
	 */
	public function search(string $uid, array $filter): array {
		[$from, $to] = $this->period($filter['from'] ?? null, $filter['to'] ?? null);
		$companyId = $filter['companyId'] ?? null;
		$noCompany = $companyId === 'none';
		return $this->mapper->search(
			$from, $to,
			$this->teams->teamIdsFor($uid),
			isset($filter['teamId']) ? (int)$filter['teamId'] : null,
			!$noCompany && $companyId !== null && $companyId !== '' ? (int)$companyId : null,
			$noCompany,
			isset($filter['uid']) && $filter['uid'] !== '' ? (string)$filter['uid'] : null,
			$filter['billable'] ?? null,
			$filter['invoiced'] ?? null,
		);
	}

	/**
	 * Marks billable, not yet invoiced entries as invoiced.
	 *
	 * @param int[] $ids
	 * @throws ValidationException
	 */
	public function markInvoiced(string $uid, array $ids): int {
		if (!$this->groupManager->isAdmin($uid)) {
			throw new ValidationException('Only administrators can mark time as invoiced');
		}
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
		return $ids === [] ? 0 : $this->mapper->markInvoiced($ids, $uid, $this->time->getTime());
	}

	/** @return array{0: ?int, 1: string} price per hour (minor units) and currency for new time on this ticket */
	public function rateFor(Ticket $ticket, ?Team $team = null): array {
		$team ??= $this->teams->find($ticket->getTeamId());
		$company = $this->companies->find($ticket->getCompanyId());
		$rate = $company?->getHourlyRate() ?? $team?->getHourlyRate();
		return [$rate, $team?->getCurrencyCode() ?? Team::DEFAULT_CURRENCY];
	}

	/** @return array{0: string, 1: string} a valid Y-m-d period, this month by default */
	public function period(?string $from, ?string $to): array {
		$valid = fn (?string $d) => $d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4));
		$today = $this->today();
		$from = $valid($from) ? $from : substr($today, 0, 8) . '01';
		$to = $valid($to) ? $to : $today;
		return $from <= $to ? [$from, $to] : [$to, $from];
	}

	/** @throws NotFoundException|ValidationException */
	private function getEditable(string $uid, int $ticketId, int $entryId): TimeEntry {
		try {
			$entry = $this->mapper->find($entryId);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Time entry not found');
		}
		if ($entry->getTicketId() !== $ticketId) {
			throw new NotFoundException('Time entry not found');
		}
		if ($entry->getUid() !== $uid && !$this->groupManager->isAdmin($uid)) {
			throw new ValidationException('You can only change your own time');
		}
		if ($entry->getInvoicedAt() !== null) {
			throw new ValidationException('This time is invoiced already and can no longer be changed');
		}
		return $entry;
	}

	/** @throws ValidationException */
	private function apply(TimeEntry $entry, array $data): void {
		if (array_key_exists('minutes', $data) || $entry->getMinutes() === null) {
			$minutes = (int)($data['minutes'] ?? 0);
			if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
				throw new ValidationException('Time must be between 1 minute and 24 hours');
			}
			$entry->setMinutes($minutes);
		}
		if (array_key_exists('billable', $data)) {
			$entry->setBillable((bool)$data['billable']);
		}
		if (array_key_exists('note', $data)) {
			$note = trim((string)($data['note'] ?? ''));
			$entry->setNote($note !== '' ? mb_substr($note, 0, 500) : null);
		}
		if (array_key_exists('workDate', $data) && $data['workDate'] !== null && $data['workDate'] !== '') {
			$date = (string)$data['workDate'];
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
				throw new ValidationException('Unknown date');
			}
			if ($date > $this->today('+1 day')) {
				throw new ValidationException('Time cannot be logged for a future day');
			}
			$entry->setWorkDate($date);
		}
	}

	private function today(string $modify = 'now'): string {
		// The agent's own time zone, as Nextcloud knows it.
		$tz = $this->timeZone->getTimeZone();
		return (new \DateTimeImmutable('@' . $this->time->getTime()))->setTimezone($tz)->modify($modify)->format('Y-m-d');
	}
}
