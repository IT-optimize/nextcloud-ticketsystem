<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\TagService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/** Tags for agents, under /ocs/v2.php/apps/ticketsystem/api/v1/. */
class OrganizeApiController extends OCSController {
	use RunsAsAgent;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AgentService $agents,
		private TagService $tags,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Creates a tag, or returns the existing one with that name. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tags')]
	public function createTag(string $name, ?string $color = null): DataResponse {
		return $this->run(fn () => ['tag' => $this->tags->findOrCreate($name, $color)], Http::STATUS_CREATED);
	}
}
