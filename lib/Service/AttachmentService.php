<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\Attachment;
use OCA\TicketSystem\Db\AttachmentMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException as FileNotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Files on messages: attachments of customers' emails and files agents add
 * to replies and notes.
 *
 * The content is kept in Ticket system's app data, one folder per ticket, not in
 * anyone's Files: email intake runs without a user. Every download goes
 * through Ticket system, which checks who may see the message.
 */
class AttachmentService {
	/** Largest file an agent can upload. */
	public const MAX_FILE = 20 * 1024 * 1024;
	/** Largest total of files on one reply, so the email still gets through. */
	public const MAX_REPLY = 20 * 1024 * 1024;
	/** Uploads that never got sent are deleted after this long. */
	private const ABANDONED_AFTER = 86400;

	public function __construct(
		private AttachmentMapper $mapper,
		private IAppData $appData,
		private IRootFolder $rootFolder,
		private IURLGenerator $url,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/** Saves a file; $messageId null means it waits for its message. */
	public function store(int $ticketId, ?int $messageId, string $filename, string $mimeType, string $content, ?string $uploadedBy): Attachment {
		$attachment = new Attachment();
		$attachment->setTicketId($ticketId);
		$attachment->setMessageId($messageId);
		$attachment->setFilename(self::cleanFilename($filename));
		$attachment->setMimeType(self::cleanMimeType($mimeType));
		$attachment->setSize(strlen($content));
		$attachment->setContentHash(sha1($content));
		$attachment->setUploadedBy($uploadedBy);
		$attachment->setCreatedAt($this->time->getTime());
		$attachment = $this->mapper->insert($attachment);
		try {
			$this->folder($ticketId, true)->newFile((string)$attachment->getId(), $content);
		} catch (\Throwable $e) {
			$this->mapper->delete($attachment);
			throw $e;
		}
		return $attachment;
	}

	/**
	 * An agent's upload, from IRequest::getUploadedFile().
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $file
	 * @throws ValidationException
	 */
	public function upload(string $uid, int $ticketId, ?array $file): Attachment {
		if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
			throw new ValidationException('Choose a file to upload');
		}
		if (in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($file['size'] ?? 0) > self::MAX_FILE) {
			throw new ValidationException(sprintf('Files can be at most %d MB', self::MAX_FILE / 1024 / 1024));
		}
		$tmp = (string)($file['tmp_name'] ?? '');
		if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
			throw new ValidationException('The upload did not arrive, please try again');
		}
		$content = file_get_contents($tmp);
		if ($content === false) {
			throw new ValidationException('The upload did not arrive, please try again');
		}
		$type = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: '') : '';
		return $this->store($ticketId, null, (string)($file['name'] ?? ''), $type ?: (string)($file['type'] ?? ''), $content, $uid);
	}

	/**
	 * Files a customer sent with a public page (the portal), from
	 * IRequest::getUploadedFile(). Null when there are too many, they are too
	 * large together, or an upload broke.
	 *
	 * @param array<string, mixed>|null $upload one file or several (PHP's parallel lists)
	 * @return list<array{filename: string, contentType: string, content: string}>|null
	 */
	public function readCustomerUploads(?array $upload, int $maxFiles, int $maxTotal): ?array {
		if (!is_array($upload) || !isset($upload['name'])) {
			return [];
		}
		$files = [];
		$total = 0;
		foreach ((array)$upload['name'] as $i => $fileName) {
			$error = ((array)$upload['error'])[$i] ?? UPLOAD_ERR_NO_FILE;
			if ($error === UPLOAD_ERR_NO_FILE) {
				continue;
			}
			$tmp = (string)(((array)$upload['tmp_name'])[$i] ?? '');
			$total += (int)(((array)$upload['size'])[$i] ?? 0);
			if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp) || count($files) >= $maxFiles || $total > $maxTotal) {
				return null;
			}
			$content = file_get_contents($tmp);
			if ($content === false) {
				return null;
			}
			$type = function_exists('mime_content_type') ? (mime_content_type($tmp) ?: '') : '';
			$files[] = ['filename' => (string)$fileName, 'contentType' => $type ?: (string)(((array)$upload['type'])[$i] ?? ''), 'content' => $content];
		}
		return $files;
	}

	/**
	 * Checks files an agent wants on a message or scheduled reply: their own
	 * uploads on this ticket that are not on anything yet, or the files
	 * waiting on the scheduled reply that is being sent now.
	 *
	 * @param int[] $ids
	 * @return Attachment[]
	 * @throws ValidationException
	 */
	public function claim(string $uid, int $ticketId, array $ids, ?int $fromScheduledId = null): array {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$found = $this->mapper->findMany($ids);
		if (count($found) !== count($ids)) {
			throw new ValidationException('A file is missing, please add it again');
		}
		$total = 0;
		foreach ($found as $attachment) {
			$waiting = $attachment->getMessageId() === null
				&& ($fromScheduledId !== null ? $attachment->getScheduledId() === $fromScheduledId : $attachment->getScheduledId() === null);
			if ($attachment->getTicketId() !== $ticketId || !$waiting
				|| ($fromScheduledId === null && $attachment->getUploadedBy() !== $uid)) {
				throw new ValidationException('A file is missing, please add it again');
			}
			$total += $attachment->getSize();
		}
		if ($total > self::MAX_REPLY) {
			throw new ValidationException(sprintf('The files on one reply can be at most %d MB together', self::MAX_REPLY / 1024 / 1024));
		}
		return $found;
	}

	/** @param Attachment[] $attachments */
	public function linkToMessage(array $attachments, int $messageId): void {
		foreach ($attachments as $attachment) {
			$attachment->setMessageId($messageId);
			$attachment->setScheduledId(null);
			$this->mapper->update($attachment);
		}
	}

	/** @param Attachment[] $attachments */
	public function linkToScheduled(array $attachments, int $scheduledId): void {
		foreach ($attachments as $attachment) {
			$attachment->setScheduledId($scheduledId);
			$this->mapper->update($attachment);
		}
	}

	/** Whether this sender already sent exactly this file, on any ticket. */
	public function sentBefore(string $content, string $email): bool {
		return $this->mapper->existsFromSender(sha1($content), $email);
	}

	/** @return Attachment[] */
	public function forScheduled(int $scheduledId): array {
		return $this->mapper->findForScheduled($scheduledId);
	}

	/** A cancelled scheduled reply lets go of its files; the cleanup removes them later. */
	public function releaseScheduled(int $scheduledId): void {
		foreach ($this->mapper->findForScheduled($scheduledId) as $attachment) {
			$attachment->setScheduledId(null);
			$this->mapper->update($attachment);
		}
	}

	/**
	 * @param int[] $messageIds
	 * @return array<int, Attachment[]> by message id
	 */
	public function forMessages(array $messageIds): array {
		$byMessage = [];
		foreach ($this->mapper->findForMessages($messageIds) as $attachment) {
			$byMessage[(int)$attachment->getMessageId()][] = $attachment;
		}
		return $byMessage;
	}

	/** @throws NotFoundException */
	public function get(int $id): Attachment {
		try {
			return $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('File not found');
		}
	}

	/** @throws NotFoundException */
	public function file(Attachment $attachment): ISimpleFile {
		try {
			return $this->folder($attachment->getTicketId(), false)->getFile((string)$attachment->getId());
		} catch (FileNotFoundException) {
			throw new NotFoundException('File not found');
		}
	}

	/** @param Attachment[] $attachments */
	public function forAgent(array $attachments): array {
		return array_map(fn (Attachment $a) => $a->toArray($this->url->linkToRoute('ticketsystem.attachment.download', ['id' => $a->getId()])), $attachments);
	}

	/** @param Attachment[] $attachments */
	public function forPortal(string $token, array $attachments): array {
		return array_map(fn (Attachment $a) => $a->toArray($this->url->linkToRoute('ticketsystem.portal.attachment', ['token' => $token, 'id' => $a->getId()])), $attachments);
	}

	/**
	 * Copies a file into the agent's own Files, folder "Ticket system".
	 *
	 * @return string the path in the agent's Files
	 * @throws NotFoundException
	 */
	public function saveToUserFiles(string $uid, Attachment $attachment): string {
		$home = $this->rootFolder->getUserFolder($uid);
		$folder = $home->nodeExists('Ticket system') ? $home->get('Ticket system') : $home->newFolder('Ticket system');
		if (!$folder instanceof \OCP\Files\Folder) {
			throw new NotFoundException('There is a file called Ticket system in your Files; rename it to save attachments');
		}
		$name = $folder->getNonExistingName($attachment->getFilename());
		$folder->newFile($name, $this->file($attachment)->getContent());
		return '/Ticket system/' . $name;
	}

	/**
	 * Deletes files for good (customer data erasure): of whole tickets, or of
	 * single messages.
	 *
	 * @param int[] $ticketIds
	 * @param int[] $messageIds
	 * @return int how many files were deleted
	 */
	public function deleteFor(array $ticketIds, array $messageIds = []): int {
		$attachments = [];
		foreach ($this->mapper->findForMessages($messageIds) as $attachment) {
			$attachments[$attachment->getId()] = $attachment;
		}
		foreach ($ticketIds as $ticketId) {
			foreach ($this->mapper->findForTicket($ticketId) as $attachment) {
				$attachments[$attachment->getId()] = $attachment;
			}
		}
		$deleted = 0;
		foreach ($attachments as $attachment) {
			try {
				$this->file($attachment)->delete();
			} catch (NotFoundException) {
			}
			$this->mapper->delete($attachment);
			$deleted++;
		}
		return $deleted;
	}

	/** Deletes uploads that never got sent (background job). */
	public function deleteAbandoned(): int {
		$deleted = 0;
		foreach ($this->mapper->findAbandoned($this->time->getTime() - self::ABANDONED_AFTER) as $attachment) {
			try {
				$this->file($attachment)->delete();
			} catch (NotFoundException) {
			} catch (\Throwable $e) {
				$this->logger->warning('Ticket system could not delete an unsent upload', ['exception' => $e]);
				continue;
			}
			$this->mapper->delete($attachment);
			$deleted++;
		}
		return $deleted;
	}

	/** "../evil\n.exe" → "evil.exe": no paths, no control characters, at most 255 bytes. */
	public static function cleanFilename(string $name): string {
		$name = str_replace('\\', '/', $name);
		$name = basename($name);
		$name = trim(preg_replace('/[\x00-\x1f\x7f"*:<>?|]+/u', '', $name) ?? '', " .");
		if ($name === '') {
			return 'attachment';
		}
		if (strlen($name) > 255) {
			$dot = strrpos($name, '.');
			$extension = $dot !== false && strlen($name) - $dot <= 16 ? substr($name, $dot) : '';
			$name = mb_strcut($name, 0, 255 - strlen($extension)) . $extension;
		}
		return $name;
	}

	public static function cleanMimeType(string $type): string {
		$type = strtolower(trim(explode(';', $type)[0]));
		return preg_match('#^[a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*$#', $type) ? $type : 'application/octet-stream';
	}

	private function folder(int $ticketId, bool $create): ISimpleFolder {
		try {
			$root = $this->appData->getFolder('attachments');
		} catch (FileNotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			$root = $this->appData->newFolder('attachments');
		}
		try {
			return $root->getFolder((string)$ticketId);
		} catch (FileNotFoundException $e) {
			if (!$create) {
				throw $e;
			}
			return $root->newFolder((string)$ticketId);
		}
	}
}
