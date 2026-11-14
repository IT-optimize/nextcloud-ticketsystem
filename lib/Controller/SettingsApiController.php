<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCA\TicketSystem\Mail\ImapException;
use OCA\TicketSystem\Service\AdminData;
use OCA\TicketSystem\Service\MailIntakeService;
use OCA\TicketSystem\Service\SettingsService;
use OCA\TicketSystem\Service\TagService;
use OCA\TicketSystem\Service\TeamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/** Admin-only (no #[NoAdminRequired]): Ticket system settings. */
class SettingsApiController extends OCSController {
	private const KEYS = [
		'statuses', 'priorities', 'sendCustomerEmails', 'sendAcknowledgment', 'portalEnabled',
		'supportAddress', 'senderName', 'intakeEnabled', 'imapHost', 'imapPort', 'imapSecurity', 'imapVerifyTls',
		'imapUser', 'imapPassword', 'imapFolder', 'imapProcessedFolder', 'retentionMonths', 'customerLanguage',
	];

	public function __construct(
		IRequest $request,
		private SettingsService $settings,
		private MailIntakeService $intake,
		private TeamService $teams,
		private TagService $tags,
		private AdminData $data,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[ApiRoute(verb: 'GET', url: '/api/v1/settings')]
	public function show(): DataResponse {
		return new DataResponse($this->all());
	}

	#[ApiRoute(verb: 'PUT', url: '/api/v1/settings')]
	public function update(): DataResponse {
		$values = array_intersect_key($this->request->getParams(), array_flip(self::KEYS));
		try {
			$this->settings->update($values);
			return new DataResponse($this->all());
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Tries the IMAP settings from the form (falling back to the saved
	 * password) without saving or importing anything.
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/settings/test-imap')]
	public function testImap(): DataResponse {
		$values = array_intersect_key($this->request->getParams(), array_flip(self::KEYS));
		try {
			$config = $this->settings->imapConfigFromInput($values);
			return new DataResponse($this->intake->testConnection($config));
		} catch (ValidationException|ImapException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Tries a brand's own inbox settings from the form (falling back to the
	 * saved password) without saving or importing anything.
	 *
	 * @param int|null $teamId the brand being edited; null for a new brand
	 * @param array<string, mixed> $inbox host, port, security, verifyTls, user, password, folder, processedFolder
	 */
	#[ApiRoute(verb: 'POST', url: '/api/v1/teams/test-inbox')]
	public function testTeamInbox(?int $teamId = null, array $inbox = []): DataResponse {
		try {
			$config = $this->teams->inboxForTest($teamId, $inbox);
			return new DataResponse($this->intake->testConnection($config));
		} catch (ValidationException|ImapException|NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	/** Reads every inbox now, so admins don't have to wait for cron. */
	#[ApiRoute(verb: 'POST', url: '/api/v1/settings/fetch-mail')]
	public function fetchMail(): DataResponse {
		$result = $this->intake->run(true);
		if ($result['errors'] !== []) {
			return new DataResponse(['message' => implode("\n", $result['errors']), 'result' => $result, 'settings' => $this->all()], Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse(['result' => $result, 'settings' => $this->all()]);
	}

	// --- Brands -------------------------------------------------------------

	#[ApiRoute(verb: 'POST', url: '/api/v1/teams')]
	public function createTeam(): DataResponse {
		return $this->guard(function () {
			$this->teams->create($this->teamInput());
			return $this->all();
		}, Http::STATUS_CREATED);
	}

	#[ApiRoute(verb: 'PUT', url: '/api/v1/teams/{id}', requirements: ['id' => '\d+'])]
	public function updateTeam(int $id): DataResponse {
		return $this->guard(function () use ($id) {
			$input = $this->teamInput();
			$this->teams->update($id, $input);
			if ($id === $this->teams->getDefault()->getId()) {
				$this->teams->confirmCompany();
			}
			return $this->all();
		});
	}

	/** @param int|null $moveTo brand that receives this brand's tickets */
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/teams/{id}', requirements: ['id' => '\d+'])]
	public function deleteTeam(int $id, ?int $moveTo = null): DataResponse {
		return $this->guard(function () use ($id, $moveTo) {
			$this->teams->delete($id, $moveTo);
			return $this->all();
		});
	}

	// --- Tags ---------------------------------------------------------------

	#[ApiRoute(verb: 'PUT', url: '/api/v1/tags/{id}', requirements: ['id' => '\d+'])]
	public function updateTag(int $id, ?string $name = null, ?string $color = null): DataResponse {
		return $this->guard(function () use ($id, $name, $color) {
			$this->tags->update($id, $name, $color);
			return $this->all();
		});
	}

	#[ApiRoute(verb: 'DELETE', url: '/api/v1/tags/{id}', requirements: ['id' => '\d+'])]
	public function deleteTag(int $id): DataResponse {
		return $this->guard(function () use ($id) {
			$this->tags->delete($id);
			return $this->all();
		});
	}

	/** Settings plus brands and tags: everything the admin page edits. */
	private function all(): array {
		return $this->data->get();
	}

	private function teamInput(): array {
		return array_intersect_key($this->request->getParams(),
			array_flip(['name', 'agentGroups', 'senderName', 'replyTo', 'signature', 'color', 'language', 'isDefault', 'hourlyRate', 'currency', 'inbox']));
	}


	private function guard(callable $fn, int $status = Http::STATUS_OK): DataResponse {
		try {
			return new DataResponse($fn(), $status);
		} catch (ValidationException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (NotFoundException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}
}
