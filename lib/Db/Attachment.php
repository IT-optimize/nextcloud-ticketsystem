<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A file on a message. The content lives in Ticket system's app data
 * (see AttachmentService), named by the id.
 *
 * @method int getTicketId()
 * @method void setTicketId(int $id)
 * @method ?int getMessageId()
 * @method void setMessageId(?int $id)
 * @method ?int getScheduledId()
 * @method void setScheduledId(?int $id)
 * @method string getFilename()
 * @method void setFilename(string $name)
 * @method string getMimeType()
 * @method void setMimeType(string $type)
 * @method int getSize()
 * @method void setSize(int $bytes)
 * @method ?string getUploadedBy()
 * @method void setUploadedBy(?string $uid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method ?string getContentHash()
 * @method void setContentHash(?string $sha1)
 */
class Attachment extends Entity {
	/** Shown in the browser as pictures; everything else (SVG too) is only downloaded. */
	public const PREVIEW_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

	protected $ticketId;
	protected $messageId;
	protected $scheduledId;
	protected $filename;
	protected $mimeType;
	protected $size;
	protected $uploadedBy;
	protected $createdAt;
	protected $contentHash;

	public function __construct() {
		$this->addType('ticketId', Types::BIGINT);
		$this->addType('messageId', Types::BIGINT);
		$this->addType('scheduledId', Types::BIGINT);
		$this->addType('size', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
	}

	public function isImage(): bool {
		return in_array($this->mimeType, self::PREVIEW_TYPES, true);
	}

	/** @return array{id: int, name: string, mimeType: string, size: int, isImage: bool, url: string} */
	public function toArray(string $url): array {
		return [
			'id' => $this->getId(),
			'name' => $this->filename,
			'mimeType' => $this->mimeType,
			'size' => $this->size,
			'isImage' => $this->isImage(),
			'url' => $url,
		];
	}
}
