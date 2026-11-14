<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Exception\ValidationException;
use OCA\TicketSystem\Service\PrivacyService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Admin only (no NoAdminRequired): a customer's data under the GDPR, found
 * by email address. Erasing can't be undone; the page has the admin type the
 * address again before it calls erase.
 */
class PrivacyApiController extends OCSController {
	public function __construct(
		IRequest $request,
		private PrivacyService $privacy,
		private IUserSession $userSession,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/privacy')]
	public function summary(string $email = ''): DataResponse {
		return $this->guard(fn () => $this->privacy->summary($email));
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/privacy/export')]
	public function export(string $email = ''): DataResponse {
		return $this->guard(fn () => $this->privacy->export($email));
	}

	#[ApiRoute(verb: 'POST', url: '/api/v1/privacy/erase')]
	public function erase(string $email = ''): DataResponse {
		return $this->guard(fn () => $this->privacy->erase($email, $this->userSession->getUser()?->getUID()));
	}

	private function guard(callable $fn): DataResponse {
		try {
			return new DataResponse($fn());
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
