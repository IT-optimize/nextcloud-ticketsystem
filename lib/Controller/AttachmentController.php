<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Controller;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Attachment;
use OCA\TicketSystem\Exception\ForbiddenException;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Service\AgentService;
use OCA\TicketSystem\Service\AttachmentService;
use OCA\TicketSystem\Service\TicketService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\Response;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\IRequest;
use OCP\IUserSession;

/** Agents download files of tickets on their brands (links and image previews). */
class AttachmentController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private AgentService $agents,
		private TicketService $tickets,
		private AttachmentService $attachments,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/** @param bool $inline show a picture in the page instead of downloading it */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[FrontpageRoute(verb: 'GET', url: '/attachments/{id}', requirements: ['id' => '\d+'])]
	public function download(int $id, bool $inline = false): Response {
		$uid = $this->userSession->getUser()?->getUID();
		try {
			$this->agents->requireAgent($uid);
			$attachment = $this->attachments->get($id);
			$this->tickets->getForAgent($uid, $attachment->getTicketId());
			return self::fileResponse($attachment, $this->attachments->file($attachment), $inline);
		} catch (ForbiddenException|NotFoundException) {
			return new DataResponse(['message' => 'File not found'], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * The file as a download. Only the picture types in Attachment::PREVIEW_TYPES
	 * may be shown inline; anything else (HTML, SVG, PDF) is always downloaded,
	 * so a customer's file can never run in Nextcloud's pages.
	 */
	public static function fileResponse(Attachment $attachment, ISimpleFile $file, bool $inline): FileDisplayResponse {
		$inline = $inline && $attachment->isImage();
		$response = new FileDisplayResponse($file, Http::STATUS_OK, [
			'Content-Type' => $inline ? $attachment->getMimeType() : 'application/octet-stream',
		]);
		$name = $attachment->getFilename();
		$ascii = preg_replace('/[^\x20-\x7e]|["\\\\]/u', '_', $name) ?: 'attachment';
		$response->addHeader('Content-Disposition', sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $inline ? 'inline' : 'attachment', $ascii, rawurlencode($name)));
		$response->addHeader('X-Content-Type-Options', 'nosniff');
		$response->cacheFor(3600, false, true);
		return $response;
	}
}
