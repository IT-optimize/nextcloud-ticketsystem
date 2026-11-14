<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\ScheduleService;
use OCA\TicketSystem\Service\TicketService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/** Replies scheduled for later, under /ocs/v2.php/apps/ticketsystem/api/v1/. */
class ScheduleApiController extends OCSController {
	use RunsAsAgent;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AgentService $agents,
		private TicketService $tickets,
		private ScheduleService $schedule,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @param int $sendAt unix time
	 * @param string|null $thenStatus status to set after sending
	 * @param int[] $attachmentIds files uploaded for this reply
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets/{id}/scheduled', requirements: ['id' => '\d+'])]
	public function create(int $id, int $sendAt, string $body = '', ?string $thenStatus = null, array $attachmentIds = []): DataResponse {
		return $this->run(function (string $uid) use ($id, $body, $sendAt, $thenStatus, $attachmentIds) {
			$ticket = $this->tickets->getForAgent($uid, $id);
			$reply = $this->schedule->schedule($uid, $ticket, $body, $sendAt, $thenStatus, $attachmentIds);
			return ['reply' => $reply, 'scheduled' => $this->schedule->pending($id)];
		}, Http::STATUS_CREATED);
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/tickets/{id}/scheduled/{replyId}', requirements: ['id' => '\d+', 'replyId' => '\d+'])]
	public function update(int $id, int $replyId): DataResponse {
		return $this->run(function (string $uid) use ($id, $replyId) {
			$this->tickets->getForAgent($uid, $id);
			$input = array_intersect_key($this->request->getParams(), array_flip(['body', 'sendAt', 'thenStatus']));
			$this->schedule->update($uid, $id, $replyId, $input);
			return ['scheduled' => $this->schedule->pending($id)];
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'DELETE', url: '/api/v1/tickets/{id}/scheduled/{replyId}', requirements: ['id' => '\d+', 'replyId' => '\d+'])]
	public function cancel(int $id, int $replyId): DataResponse {
		return $this->run(function (string $uid) use ($id, $replyId) {
			$this->tickets->getForAgent($uid, $id);
			$reply = $this->schedule->cancel($uid, $id, $replyId);
			return ['reply' => $reply, 'scheduled' => $this->schedule->pending($id)];
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets/{id}/scheduled/{replyId}/send', requirements: ['id' => '\d+', 'replyId' => '\d+'])]
	public function sendNow(int $id, int $replyId): DataResponse {
		return $this->run(function (string $uid) use ($id, $replyId) {
			$this->tickets->getForAgent($uid, $id);
			$this->schedule->sendNow($uid, $id, $replyId);
			return ['scheduled' => $this->schedule->pending($id), 'ticket' => $this->tickets->get($id)];
		});
	}
}
