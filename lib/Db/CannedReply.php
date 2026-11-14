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
 * A saved reply agents insert into the composer. Placeholders such as
 * {customer_first_name} are filled in by the agent UI when inserted.
 *
 * @method string getTitle()
 * @method void setTitle(string $title)
 * @method string getBody()
 * @method void setBody(string $body)
 * @method ?string getOwnerUid()
 * @method void setOwnerUid(?string $uid)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $uid)
 * @method int getUsageCount()
 * @method void setUsageCount(int $count)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 * @method ?int getTeamId()
 * @method void setTeamId(?int $id)
 */
class CannedReply extends Entity implements JsonSerializable {
	// No default values: see Ticket.
	protected $title;
	protected $body;
	protected $ownerUid;
	protected $createdBy;
	protected $usageCount;
	protected $createdAt;
	protected $updatedAt;
	protected $teamId;

	public function __construct() {
		$this->addType('usageCount', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('teamId', Types::BIGINT);
	}

	public function isShared(): bool {
		return $this->ownerUid === null;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'title' => $this->title,
			'body' => $this->body,
			'shared' => $this->isShared(),
			'createdBy' => $this->createdBy,
			'usageCount' => $this->usageCount,
			'updatedAt' => $this->updatedAt,
			'teamId' => $this->teamId,
		];
	}
}
