<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\CannedReplyService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/** Canned replies for agents, under /ocs/v2.php/apps/ticketsystem/api/v1/canned. */
class CannedReplyApiController extends OCSController {
	use RunsAsAgent;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AgentService $agents,
		private CannedReplyService $canned,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/canned')]
	public function index(): DataResponse {
		return $this->run(fn (string $uid) => ['replies' => $this->canned->list($uid)]);
	}

	/**
	 * @param bool $shared true = visible to all agents, false = only to me
	 * @param int|null $teamId only for this brand's tickets (null = every brand)
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/canned')]
	public function create(string $title, string $body, bool $shared = true, ?int $teamId = null): DataResponse {
		return $this->run(
			fn (string $uid) => ['reply' => $this->canned->create($uid, $title, $body, $shared, $teamId)],
			Http::STATUS_CREATED,
		);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/canned/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id, ?string $title = null, ?string $body = null, ?bool $shared = null): DataResponse {
		$setTeam = array_key_exists('teamId', $this->request->getParams());
		$teamId = $setTeam && $this->request->getParam('teamId') !== null ? (int)$this->request->getParam('teamId') : null;
		return $this->run(fn (string $uid) => ['reply' => $this->canned->update($uid, $id, $title, $body, $shared, $setTeam, $teamId)]);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/canned/{id}', requirements: ['id' => '\d+'])]
	public function destroy(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->canned->delete($uid, $id);
			return [];
		});
	}

	/** Counts an insertion, so the most used replies come first. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/canned/{id}/used', requirements: ['id' => '\d+'])]
	public function used(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->canned->recordUse($uid, $id);
			return [];
		});
	}
}
