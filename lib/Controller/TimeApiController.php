<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Exception\ForbiddenException;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\CompanyService;
use OCA\TicketSystem\Service\TicketService;
use OCA\TicketSystem\Service\TimeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/** Time registration and customer companies, under /ocs/v2.php/apps/ticketsystem/api/v1/. */
class TimeApiController extends OCSController {
	use RunsAsAgent;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AgentService $agents,
		private TicketService $tickets,
		private TimeService $time,
		private CompanyService $companies,
		private IGroupManager $groupManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	// --- Time on a ticket -----------------------------------------------------

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tickets/{id}/time', requirements: ['id' => '\d+'])]
	public function ticketTime(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			$ticket = $this->tickets->getForAgent($uid, $id);
			return $this->ticketPayload($ticket);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets/{id}/time', requirements: ['id' => '\d+'])]
	public function addTime(int $id, int $minutes, bool $billable = true, ?string $note = null, ?string $workDate = null): DataResponse {
		return $this->run(function (string $uid) use ($id, $minutes, $billable, $note, $workDate) {
			$ticket = $this->tickets->getForAgent($uid, $id);
			$this->time->add($uid, $ticket, compact('minutes', 'billable', 'note', 'workDate'));
			return $this->ticketPayload($ticket);
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/tickets/{id}/time/{entryId}', requirements: ['id' => '\d+', 'entryId' => '\d+'])]
	public function updateTime(int $id, int $entryId): DataResponse {
		return $this->run(function (string $uid) use ($id, $entryId) {
			$ticket = $this->tickets->getForAgent($uid, $id);
			$input = array_intersect_key($this->request->getParams(), array_flip(['minutes', 'billable', 'note', 'workDate']));
			$this->time->update($uid, $id, $entryId, $input);
			return $this->ticketPayload($ticket);
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/tickets/{id}/time/{entryId}', requirements: ['id' => '\d+', 'entryId' => '\d+'])]
	public function deleteTime(int $id, int $entryId): DataResponse {
		return $this->run(function (string $uid) use ($id, $entryId) {
			$ticket = $this->tickets->getForAgent($uid, $id);
			$this->time->delete($uid, $id, $entryId);
			return $this->ticketPayload($ticket);
		});
	}

	// --- Overview -------------------------------------------------------------

	/**
	 * Time in a period (this month by default), for the overview and invoicing.
	 *
	 * @param string|null $companyId a company id, or "none" for tickets without one
	 * @param string|null $billable "1" | "0"
	 * @param string|null $invoiced "1" | "0"
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/time')]
	public function overview(?string $from = null, ?string $to = null, ?int $teamId = null, ?string $companyId = null, ?string $uid = null, ?string $billable = null, ?string $invoiced = null): DataResponse {
		return $this->run(function (string $me) use ($from, $to, $teamId, $companyId, $uid, $billable, $invoiced) {
			$flag = fn (?string $v) => $v === null || $v === '' ? null : $v === '1';
			[$from, $to] = $this->time->period($from, $to);
			$rows = $this->time->search($me, [
				'from' => $from, 'to' => $to, 'teamId' => $teamId, 'companyId' => $companyId, 'uid' => $uid,
				'billable' => $flag($billable), 'invoiced' => $flag($invoiced),
			]);
			return [
				'from' => $from,
				'to' => $to,
				'entries' => array_map(fn (array $row) => $row['entry']->jsonSerialize() + [
					'teamId' => $row['teamId'],
					'companyId' => $row['companyId'],
					'subject' => $row['subject'],
					'customerEmail' => $row['customerEmail'],
				], $rows),
				'companies' => $this->companies->all(),
			];
		});
	}

	/** @param int[] $ids */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/time/invoiced')]
	public function markInvoiced(array $ids): DataResponse {
		return $this->run(fn (string $uid) => ['marked' => $this->time->markInvoiced($uid, $ids)]);
	}

	// --- Companies ------------------------------------------------------------

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/companies')]
	public function companies(): DataResponse {
		return $this->run(fn () => $this->companyPayload());
	}

	/** @param string[] $domains */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/companies')]
	public function createCompany(string $name, array $domains = [], ?int $hourlyRate = null, ?string $notes = null): DataResponse {
		return $this->run(function (string $uid) use ($name, $domains, $hourlyRate, $notes) {
			$data = compact('name', 'domains', 'notes');
			if ($this->groupManager->isAdmin($uid)) {
				$data['hourlyRate'] = $hourlyRate;
			}
			$company = $this->companies->create($data);
			return ['company' => $company] + $this->companyPayload();
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/companies/{id}', requirements: ['id' => '\d+'])]
	public function updateCompany(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			$input = array_intersect_key($this->request->getParams(), array_flip(['name', 'domains', 'hourlyRate', 'notes']));
			$company = $this->companies->update($id, $input, $this->groupManager->isAdmin($uid));
			return ['company' => $company] + $this->companyPayload();
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/companies/{id}', requirements: ['id' => '\d+'])]
	public function deleteCompany(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			if (!$this->groupManager->isAdmin($uid)) {
				throw new ForbiddenException('Only administrators can delete companies');
			}
			$this->companies->delete($id);
			return $this->companyPayload();
		});
	}

	private function companyPayload(): array {
		return [
			'companies' => $this->companies->all(),
			'ticketCounts' => (object)$this->companies->ticketCounts(),
		];
	}

	private function ticketPayload(\OCA\TicketSystem\Db\Ticket $ticket): array {
		[$rate, $currency] = $this->time->rateFor($ticket);
		return [
			'entries' => $this->time->forTicket($ticket->getId()),
			'rate' => $rate,
			'currency' => $currency,
		];
	}
}
