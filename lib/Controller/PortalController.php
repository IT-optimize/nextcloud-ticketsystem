<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Message;
use OCA\TicketSystem\Db\Ticket;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\AttachmentService;
use OCA\TicketSystem\Service\CustomerMailer;
use OCA\TicketSystem\Service\SettingsService;
use OCA\TicketSystem\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Util;

/**
 * The customer's private page for one ticket, reached through the secret
 * link in every email. Shows only public messages; the customer can answer
 * or mark the request as solved. Wrong links are throttled.
 */
class PortalController extends Controller {
	private const MAX_BODY = 20000;
	private const MAX_FILES = 5;
	private const MAX_FILES_SIZE = 10 * 1024 * 1024;

	public function __construct(
		IRequest $request,
		private TicketService $tickets,
		private SettingsService $settings,
		private AgentService $agents,
		private CustomerMailer $mailer,
		private AttachmentService $attachments,
		private IInitialState $initialState,
		private IL10N $l,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'ticketsystem_portal')]
	#[FrontpageRoute(verb: 'GET', url: '/portal/{token}')]
	public function show(string $token): TemplateResponse {
		$ticket = $this->findTicket($token);
		if ($ticket === null) {
			$response = new TemplateResponse('core', '404', [], TemplateResponse::RENDER_AS_GUEST, Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'ticketsystem_portal']);
			return $response;
		}
		$this->initialState->provideInitialState('portal', $this->portalData($ticket));
		Util::addScript(Application::APP_ID, Application::APP_ID . '-portal');

		$response = new PublicTemplateResponse(Application::APP_ID, 'portal');
		$response->setHeaderTitle($this->mailer->senderName($ticket));
		$response->setHeaderDetails($ticket->getReference());
		$response->setFooterVisible(false);
		return $response;
	}

	#[PublicPage]
	#[BruteForceProtection(action: 'ticketsystem_portal')]
	#[AnonRateLimit(limit: 10, period: 600)]
	#[UserRateLimit(limit: 30, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/portal/{token}/messages')]
	public function reply(string $token, string $body = ''): JSONResponse {
		$ticket = $this->findTicket($token);
		if ($ticket === null) {
			return $this->notFound();
		}
		$files = $this->attachments->readCustomerUploads($this->request->getUploadedFile('files'), self::MAX_FILES, self::MAX_FILES_SIZE);
		if ($files === null) {
			return new JSONResponse(['message' => $this->l->t('Up to %1$d files of at most %2$d MB together can be added.', [self::MAX_FILES, self::MAX_FILES_SIZE / 1024 / 1024])], Http::STATUS_BAD_REQUEST);
		}
		$body = trim($body);
		if (($body === '' && $files === []) || mb_strlen($body) > self::MAX_BODY) {
			return new JSONResponse(['message' => $this->l->t('Please write a message (at most %d characters).', [self::MAX_BODY])], Http::STATUS_BAD_REQUEST);
		}
		try {
			$this->tickets->addCustomerMessage($ticket->getId(), $ticket->getCustomerEmail(), $body, null, $files);
			return new JSONResponse($this->portalData($this->tickets->get($ticket->getId())));
		} catch (ValidationException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}

	#[PublicPage]
	#[BruteForceProtection(action: 'ticketsystem_portal')]
	#[AnonRateLimit(limit: 10, period: 600)]
	#[FrontpageRoute(verb: 'POST', url: '/portal/{token}/resolve')]
	public function resolve(string $token): JSONResponse {
		$ticket = $this->findTicket($token);
		if ($ticket === null) {
			return $this->notFound();
		}
		$ticket = $this->tickets->resolveByCustomer($ticket->getId());
		return new JSONResponse($this->portalData($ticket));
	}

	/** A file on one of the ticket's public messages; never one on an internal note. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'ticketsystem_portal')]
	#[AnonRateLimit(limit: 120, period: 600)]
	#[FrontpageRoute(verb: 'GET', url: '/portal/{token}/attachments/{id}', requirements: ['id' => '\d+'])]
	public function attachment(string $token, int $id, bool $inline = false): Response {
		$ticket = $this->findTicket($token);
		try {
			if ($ticket === null) {
				throw new NotFoundException('Ticket not found');
			}
			$attachment = $this->attachments->get($id);
			$message = $attachment->getMessageId() !== null && $attachment->getTicketId() === $ticket->getId()
				? $this->tickets->getMessage($ticket->getId(), $attachment->getMessageId())
				: null;
			if ($message === null || !$message->isPublic()) {
				throw new NotFoundException('File not found');
			}
			return AttachmentController::fileResponse($attachment, $this->attachments->file($attachment), $inline);
		} catch (NotFoundException) {
			$response = new JSONResponse(['message' => $this->l->t('This link is not valid.')], Http::STATUS_NOT_FOUND);
			$response->throttle(['action' => 'ticketsystem_portal']);
			return $response;
		}
	}

	private function findTicket(string $token): ?Ticket {
		if (!$this->settings->portalEnabled()) {
			return null;
		}
		try {
			return $this->tickets->getByToken($token);
		} catch (NotFoundException) {
			return null;
		}
	}

	private function notFound(): JSONResponse {
		$response = new JSONResponse(['message' => $this->l->t('This link is not valid.')], Http::STATUS_NOT_FOUND);
		$response->throttle(['action' => 'ticketsystem_portal']);
		return $response;
	}

	/** Everything the page shows. Internal notes never leave this method. */
	private function portalData(Ticket $ticket): array {
		$statuses = $this->settings->getStatuses();
		$status = array_values(array_filter($statuses, fn ($s) => $s['key'] === $ticket->getStatus()))[0] ?? null;
		$public = $this->tickets->getMessages($ticket->getId(), true);
		$files = $this->attachments->forMessages(array_map(fn (Message $m) => $m->getId(), $public));
		$messages = array_map(
			fn (Message $m) => $m->jsonSerializePublic($m->getAuthorType() === Message::AUTHOR_AGENT ? $this->agents->getDisplayName($m->getAuthorUid()) : null) + [
				'attachments' => $this->attachments->forPortal($ticket->getAccessToken(), $files[$m->getId()] ?? []),
			],
			$public,
		);
		return [
			'reference' => $ticket->getReference(),
			'subject' => $ticket->getSubject(),
			'status' => $status['label'] ?? $ticket->getStatus(),
			'closed' => (bool)($status['closed'] ?? false),
			'canResolve' => $this->settings->getClosedStatusKeys() !== [],
			'createdAt' => $ticket->getCreatedAt(),
			'customerName' => $ticket->getCustomerName(),
			'companyName' => $this->mailer->senderName($ticket),
			'messages' => $messages,
		];
	}
}
