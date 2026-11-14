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
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getPriority()
 * @method void setPriority(string $priority)
 * @method ?string getCustomerEmail()
 * @method void setCustomerEmail(?string $email)
 * @method ?string getCustomerName()
 * @method void setCustomerName(?string $name)
 * @method ?string getAssigneeUid()
 * @method void setAssigneeUid(?string $uid)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $uid)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string getAccessToken()
 * @method void setAccessToken(string $token)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 * @method ?int getFirstResponseAt()
 * @method void setFirstResponseAt(?int $ts)
 * @method ?int getResolvedAt()
 * @method void setResolvedAt(?int $ts)
 * @method ?int getTeamId()
 * @method void setTeamId(?int $id)
 * @method ?int getSnoozedUntil()
 * @method void setSnoozedUntil(?int $ts)
 * @method ?int getCompanyId()
 * @method void setCompanyId(?int $id)
 * @method ?int getWaitingSince()
 * @method void setWaitingSince(?int $ts)
 * @method ?string getCc()
 * @method void setCc(?string $json)
 * @method ?int getErasedAt()
 * @method void setErasedAt(?int $ts)
 */
class Ticket extends Entity implements JsonSerializable {
	// No default values on purpose: Entity only writes fields whose setter changed
	// the value, so a default equal to the set value would silently be skipped.
	protected $subject;
	protected $status;
	protected $priority;
	protected $customerEmail;
	protected $customerName;
	protected $assigneeUid;
	protected $createdBy;
	protected $channel;
	protected $accessToken;
	protected $createdAt;
	protected $updatedAt;
	protected $firstResponseAt;
	protected $resolvedAt;
	protected $teamId;
	protected $snoozedUntil;
	protected $companyId;
	protected $waitingSince;
	protected $cc;
	protected $erasedAt;

	public const MAX_CC = 10;

	public function __construct() {
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('firstResponseAt', Types::BIGINT);
		$this->addType('resolvedAt', Types::BIGINT);
		$this->addType('teamId', Types::BIGINT);
		$this->addType('snoozedUntil', Types::BIGINT);
		$this->addType('companyId', Types::BIGINT);
		$this->addType('waitingSince', Types::BIGINT);
		$this->addType('erasedAt', Types::BIGINT);
	}

	/** @return string[] addresses copied on the ticket's customer emails */
	public function getCcList(): array {
		$list = json_decode($this->cc ?? '[]', true);
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
	}

	/** @param string[] $addresses */
	public function setCcList(array $addresses): void {
		$this->setCc($addresses === [] ? null : json_encode(array_values($addresses)));
	}

	/** Reference shown to humans and used in email subjects, e.g. "#42". */
	public function getReference(): string {
		return '#' . $this->getId();
	}

	public function jsonSerialize(): array {
		// access_token is deliberately left out: it is a customer secret.
		return [
			'id' => $this->getId(),
			'reference' => $this->getReference(),
			'subject' => $this->subject,
			'status' => $this->status,
			'priority' => $this->priority,
			'customerEmail' => $this->customerEmail,
			'customerName' => $this->customerName,
			'cc' => $this->getCcList(),
			'assigneeUid' => $this->assigneeUid,
			'createdBy' => $this->createdBy,
			'channel' => $this->channel,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
			'firstResponseAt' => $this->firstResponseAt,
			'resolvedAt' => $this->resolvedAt,
			'teamId' => $this->teamId,
			'snoozedUntil' => $this->snoozedUntil,
			'companyId' => $this->companyId,
			'waitingSince' => $this->waitingSince,
			'erasedAt' => $this->erasedAt,
		];
	}
}
