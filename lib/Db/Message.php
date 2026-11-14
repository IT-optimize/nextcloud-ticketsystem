<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getTicketId()
 * @method void setTicketId(int $id)
 * @method string getAuthorType()
 * @method void setAuthorType(string $type)
 * @method ?string getAuthorUid()
 * @method void setAuthorUid(?string $uid)
 * @method ?string getAuthorEmail()
 * @method void setAuthorEmail(?string $email)
 * @method string getVisibility()
 * @method void setVisibility(string $visibility)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method ?string getMailMessageId()
 * @method void setMailMessageId(?string $id)
 * @method ?string getMailIdHash()
 * @method void setMailIdHash(?string $hash)
 * @method ?string getDeliveryStatus()
 * @method void setDeliveryStatus(?string $status)
 * @method ?string getDeliveryError()
 * @method void setDeliveryError(?string $error)
 * @method ?string getMeta()
 * @method void setMeta(?string $json)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 */
class Message extends Entity implements JsonSerializable {
	public const VISIBILITY_PUBLIC = 'public';
	public const VISIBILITY_INTERNAL = 'internal';

	public const AUTHOR_AGENT = 'agent';
	public const AUTHOR_CUSTOMER = 'customer';
	public const AUTHOR_SYSTEM = 'system';

	/** The reply email reached the mail server. */
	public const DELIVERY_SENT = 'sent';
	/** Sending failed; see delivery_error. Agents can retry. */
	public const DELIVERY_FAILED = 'failed';
	/** Not emailed on purpose (customer emails switched off). */
	public const DELIVERY_SKIPPED = 'skipped';

	// No default values on purpose: Entity only writes fields whose setter changed
	// the value, so a default equal to the set value would silently be skipped.
	protected $ticketId;
	protected $authorType;
	protected $authorUid;
	protected $authorEmail;
	protected $visibility;
	protected $body;
	protected $mailMessageId;
	protected $mailIdHash;
	protected $deliveryStatus;
	protected $deliveryError;
	protected $meta;
	protected $createdAt;

	public function __construct() {
		$this->addType('ticketId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
	}

	/** @return array<string, mixed>|null decoded structured data of a system entry */
	public function getMetaData(): ?array {
		if ($this->meta === null || $this->meta === '') {
			return null;
		}
		$data = json_decode($this->meta, true);
		return is_array($data) ? $data : null;
	}

	public function setMetaData(?array $data): void {
		$this->setMeta($data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}

	public function isPublic(): bool {
		return $this->visibility === self::VISIBILITY_PUBLIC;
	}

	/** Stores the email Message-ID together with its indexed hash. */
	public function setMailId(?string $messageId): void {
		$this->setMailMessageId($messageId !== null ? mb_substr($messageId, 0, 512) : null);
		$this->setMailIdHash($messageId !== null ? self::hashMailId($messageId) : null);
	}

	public static function hashMailId(string $messageId): string {
		return sha1(strtolower(trim($messageId, " \t<>")));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->ticketId,
			'authorType' => $this->authorType,
			'authorUid' => $this->authorUid,
			'authorEmail' => $this->authorEmail,
			'visibility' => $this->visibility,
			'body' => $this->body,
			'deliveryStatus' => $this->deliveryStatus,
			'deliveryError' => $this->deliveryError,
			'meta' => $this->getMetaData(),
			'createdAt' => $this->createdAt,
		];
	}

	/** What the customer may see: no delivery internals, no agent user ids. */
	public function jsonSerializePublic(?string $agentDisplayName): array {
		return [
			'id' => $this->getId(),
			'kind' => $this->authorType,
			'authorName' => $this->authorType === self::AUTHOR_AGENT ? $agentDisplayName : null,
			'body' => $this->body,
			'createdAt' => $this->createdAt,
		];
	}
}
