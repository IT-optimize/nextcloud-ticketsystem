<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\Team;
use OCA\TicketSystem\Exception\ValidationException;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\AttachmentService;
use OCA\TicketSystem\Service\CustomerMailer;
use OCA\TicketSystem\Service\CompanyService;
use OCA\TicketSystem\Service\ScheduleService;
use OCA\TicketSystem\Service\PresenceService;
use OCA\TicketSystem\Service\SettingsService;
use OCA\TicketSystem\Service\TagService;
use OCA\TicketSystem\Service\TeamService;
use OCA\TicketSystem\Service\TicketService;
use OCA\TicketSystem\Service\ViewService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;

/**
 * Agent API, served under /ocs/v2.php/apps/ticketsystem/api/v1/…
 * Also the API that add-ins and outside tools use (app passwords work).
 * Agents only see and change tickets of the brands they work on.
 */
class TicketApiController extends OCSController {
	use RunsAsAgent;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private AgentService $agents,
		private SettingsService $settings,
		private TeamService $teams,
		private TagService $tags,
		private ViewService $views,
		private TicketService $tickets,
		private CustomerMailer $mailer,
		private PresenceService $presence,
		private INotificationManager $notifications,
		private CompanyService $companies,
		private ScheduleService $schedule,
		private AttachmentService $attachments,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** Everything the agent UI needs to render lists and forms. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/meta')]
	public function meta(): DataResponse {
		return $this->run(function (string $uid) {
			return [
				'currentUser' => $uid,
				'isAdmin' => $this->groupManager->isAdmin($uid),
				'statuses' => $this->settings->getStatuses(),
				'priorities' => $this->settings->getPriorities(),
				'teams' => array_map(fn (Team $t) => [
					'id' => $t->getId(),
					'name' => $t->getName(),
					'color' => $t->getColor(),
					'signature' => $t->getSignature(),
					'senderName' => $t->getSenderName(),
					'currency' => $t->getCurrencyCode(),
				], $this->teams->teamsFor($uid)),
				'companies' => array_map(fn ($c) => ['id' => $c->getId(), 'name' => $c->getName()], $this->companies->all()),
				'defaultTeamId' => $this->teams->getDefault()->getId(),
				'agents' => $this->agents->listAgents(),
				'tags' => $this->tags->all(),
				'views' => $this->views->listFor($uid),
				'counts' => $this->tickets->counts($uid),
				'sendCustomerEmails' => $this->settings->sendCustomerEmails(),
				// what {company} in a canned reply becomes
				'senderName' => $this->teams->senderNameFor($this->teams->getDefault()),
				// admins are asked to finish the setup until the company is saved and the inbox is on
				'setup' => $this->groupManager->isAdmin($uid) ? [
					'company' => $this->teams->isCompanyConfirmed(),
					'inbox' => $this->settings->intakeEnabled(),
				] : null,
			];
		});
	}

	/**
	 * @param string $view a view id: mine, needs-reply, unassigned, waiting, open, snoozed, closed or all
	 * @param string|null $filter a filter as JSON (see ViewService); replaces the view's filter when given
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tickets')]
	public function index(string $view = 'open', ?string $q = null, ?string $filter = null, int $limit = 50, int $offset = 0): DataResponse {
		return $this->run(function (string $uid) use ($view, $q, $filter, $limit, $offset) {
			$spec = $filter !== null && $filter !== '' ? json_decode($filter, true) : null;
			if ($filter !== null && $filter !== '' && !is_array($spec)) {
				throw new ValidationException('The filter is not valid JSON');
			}
			$ticketFilter = $this->views->buildFilter($uid, $spec ?? $this->views->specFor($uid, $view), $q);
			return [
				'tickets' => $this->tickets->withPreviews($this->tickets->search($ticketFilter, $limit, $offset)),
				'total' => $this->tickets->count($ticketFilter),
			];
		});
	}

	#[NoAdminRequired]
	#[ApiRoute(verb: 'GET', url: '/api/v1/tickets/{id}', requirements: ['id' => '\d+'])]
	public function show(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			$ticket = $this->tickets->getForAgent($uid, $id);
			$this->dismissNotifications($uid, $id);
			return [
				'ticket' => $ticket,
				'messages' => $this->messagesPayload($this->tickets->getMessages($id, false)),
				// The customer's private link, for agents to share e.g. on the phone.
				'portalUrl' => $ticket->getCustomerEmail() !== null ? $this->mailer->portalUrl($ticket) : null,
				'version' => $this->tickets->version($id),
				'scheduled' => $this->schedule->pending($id),
			] + $this->tickets->details($uid, $ticket);
		});
	}

	/**
	 * @param int[] $tagIds
	 * @param string[] $cc
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets')]
	public function create(
		string $subject,
		string $body = '',
		?string $customerEmail = null,
		?string $customerName = null,
		?string $priority = null,
		?string $assigneeUid = null,
		?int $teamId = null,
		array $tagIds = [],
		array $cc = [],
	): DataResponse {
		return $this->run(function (string $uid) use ($subject, $body, $customerEmail, $customerName, $priority, $assigneeUid, $teamId, $tagIds, $cc) {
			$ticket = $this->tickets->create($uid, compact('subject', 'body', 'customerEmail', 'customerName', 'priority', 'assigneeUid', 'teamId', 'tagIds', 'cc'));
			return ['ticket' => $this->tickets->get($ticket->getId())];
		}, Http::STATUS_CREATED);
	}

	/**
	 * Partial update: send only the fields that change (status, priority,
	 * assigneeUid, subject, customerEmail, customerName, cc, teamId,
	 * snoozedUntil, tagIds).
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'PUT', url: '/api/v1/tickets/{id}', requirements: ['id' => '\d+'])]
	public function update(int $id): DataResponse {
		$allowed = ['status', 'priority', 'assigneeUid', 'subject', 'customerEmail', 'customerName', 'cc', 'teamId', 'snoozedUntil', 'tagIds'];
		$fields = array_intersect_key($this->request->getParams(), array_flip($allowed));
		return $this->run(function (string $uid) use ($id, $fields) {
			$this->tickets->getForAgent($uid, $id);
			return ['ticket' => $this->tickets->update($uid, $id, $fields)];
		});
	}

	/**
	 * @param string $visibility public (emailed to the customer) | internal (team only)
	 * @param int[] $attachmentIds files uploaded for this message (POST …/attachments)
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets/{id}/messages', requirements: ['id' => '\d+'])]
	public function addMessage(int $id, string $body = '', string $visibility = Message::VISIBILITY_INTERNAL, array $attachmentIds = []): DataResponse {
		return $this->run(function (string $uid) use ($id, $body, $visibility, $attachmentIds) {
			$this->tickets->getForAgent($uid, $id);
			$message = $this->tickets->addAgentMessage($uid, $id, $body, $visibility, $attachmentIds);
			return [
				'message' => $this->messagesPayload([$message])[0],
				'ticket' => $this->tickets->get($id),
			];
		}, Http::STATUS_CREATED);
	}

	/**
	 * Uploads one file (form field "file") for a reply or note that is being
	 * written. It is sent with the message whose attachmentIds name it;
	 * uploads that never get sent are deleted after a day.
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets/{id}/attachments', requirements: ['id' => '\d+'])]
	public function upload(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			$this->tickets->getForAgent($uid, $id);
			$attachment = $this->attachments->upload($uid, $id, $this->request->getUploadedFile('file'));
			return ['attachment' => $this->attachments->forAgent([$attachment])[0]];
		}, Http::STATUS_CREATED);
	}

	/** Copies a file of a ticket into the agent's own Files (folder "Ticket system"). */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/attachments/{id}/save', requirements: ['id' => '\d+'])]
	public function saveAttachment(int $id): DataResponse {
		return $this->run(function (string $uid) use ($id) {
			$attachment = $this->attachments->get($id);
			$this->tickets->getForAgent($uid, $attachment->getTicketId());
			return ['path' => $this->attachments->saveToUserFiles($uid, $attachment)];
		});
	}

	/**
	 * @param Message[] $messages
	 * @return list<array<string, mixed>>
	 */
	private function messagesPayload(array $messages): array {
		$files = $this->attachments->forMessages(array_map(fn (Message $m) => $m->getId(), $messages));
		return array_map(fn (Message $m) => $m->jsonSerialize() + [
			'attachments' => $this->attachments->forAgent($files[$m->getId()] ?? []),
		], $messages);
	}

	/** Sends a public reply to the customer again, e.g. after a mail server problem. */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets/{id}/messages/{messageId}/resend', requirements: ['id' => '\d+', 'messageId' => '\d+'])]
	public function resend(int $id, int $messageId): DataResponse {
		return $this->run(function (string $uid) use ($id, $messageId) {
			$ticket = $this->tickets->getForAgent($uid, $id);
			$message = $this->tickets->getMessage($id, $messageId);
			if (!$message->isPublic() || $message->getAuthorType() !== Message::AUTHOR_AGENT) {
				throw new ValidationException('Only replies to the customer can be sent again');
			}
			return ['message' => $this->messagesPayload([$this->mailer->sendReply($ticket, $message)])[0]];
		});
	}

	/**
	 * Heartbeat of an open ticket page. Records whether this agent is viewing
	 * or writing, and returns the other agents present plus the ticket's
	 * version, so the page reloads when something changed.
	 *
	 * @param string $state viewing | typing | left
	 */
	#[NoAdminRequired]
	#[ApiRoute(verb: 'POST', url: '/api/v1/tickets/{id}/presence', requirements: ['id' => '\d+'])]
	public function presence(int $id, string $state = PresenceService::VIEWING): DataResponse {
		return $this->run(function (string $uid) use ($id, $state) {
			$this->tickets->getForAgent($uid, $id);
			return [
				'others' => $this->presence->update($id, $uid, $state),
				'version' => $this->tickets->version($id),
			];
		});
	}

	/** Opening a ticket answers its notifications for this agent. */
	private function dismissNotifications(string $uid, int $ticketId): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($uid)
				->setObject('ticket', (string)$ticketId);
			$this->notifications->markProcessed($notification);
		} catch (\Throwable) {
			// Notifications are a convenience; never fail the page for them.
		}
	}
}
