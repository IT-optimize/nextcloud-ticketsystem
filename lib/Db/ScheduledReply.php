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
 * A reply to the customer that waits until a chosen time before it is sent.
 *
 * @method int getTicketId()
 * @method void setTicketId(int $id)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method ?string getThenStatus()
 * @method void setThenStatus(?string $status)
 * @method int getSendAt()
 * @method void setSendAt(int $ts)
 * @method string getState()
 * @method void setState(string $state)
 * @method ?string getError()
 * @method void setError(?string $error)
 * @method ?int getMessageId()
 * @method void setMessageId(?int $id)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 */
class ScheduledReply extends Entity implements JsonSerializable {
	public const SCHEDULED = 'scheduled';
	public const SENDING = 'sending';
	public const SENT = 'sent';
	public const FAILED = 'failed';
	public const CANCELLED = 'cancelled';

	protected $ticketId;
	protected $uid;
	protected $body;
	protected $thenStatus;
	protected $sendAt;
	protected $state;
	protected $error;
	protected $messageId;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('ticketId', Types::BIGINT);
		$this->addType('sendAt', Types::BIGINT);
		$this->addType('messageId', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->ticketId,
			'uid' => $this->uid,
			'body' => $this->body,
			'thenStatus' => $this->thenStatus,
			'sendAt' => $this->sendAt,
			'state' => $this->state,
			'error' => $this->error,
			'createdAt' => $this->createdAt,
		];
	}
}
