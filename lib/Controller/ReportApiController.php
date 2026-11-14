<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\ReportService;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/** Reports for agents, limited to the brands they work on. */
class ReportApiController extends OCSController {
	use RunsAsAgent;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AgentService $agents,
		private ReportService $reports,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param string $from first day (Y-m-d)
	 * @param string $to last day (Y-m-d), inclusive
	 * @param string $tz timezone for daily numbers, e.g. Europe/Copenhagen
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/reports')]
	public function show(string $from, string $to, ?int $teamId = null, string $tz = 'Europe/Copenhagen'): DataResponse {
		return $this->run(fn (string $uid) => $this->reports->build($uid, $from, $to, $teamId, $tz));
	}
}
