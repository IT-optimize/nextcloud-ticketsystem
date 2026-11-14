<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\Exception\ForbiddenException;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;

/**
 * For agent API controllers that have $userSession (IUserSession) and
 * $agents (AgentService): checks the caller is an agent before doing anything
 * and maps domain errors to HTTP statuses.
 */
trait RunsAsAgent {
	/**
	 * @param callable(string): mixed $fn receives the agent's user id
	 */
	private function run(callable $fn, int $successStatus = Http::STATUS_OK): DataResponse {
		$uid = $this->userSession->getUser()?->getUID();
		try {
			$this->agents->requireAgent($uid);
			return new DataResponse($fn($uid), $successStatus);
		} catch (ForbiddenException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}
}
