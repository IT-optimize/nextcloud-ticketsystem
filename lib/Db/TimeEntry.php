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
 * Time an agent spent on a ticket.
 *
 * @method int getTicketId()
 * @method void setTicketId(int $id)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getMinutes()
 * @method void setMinutes(int $minutes)
 * @method ?bool getBillable()
 * @method void setBillable(bool $billable)
 * @method ?string getNote()
 * @method void setNote(?string $note)
 * @method string getWorkDate()
 * @method void setWorkDate(string $date)
 * @method ?int getRate()
 * @method void setRate(?int $minorUnits)
 * @method ?string getCurrency()
 * @method void setCurrency(?string $code)
 * @method ?int getInvoicedAt()
 * @method void setInvoicedAt(?int $ts)
 * @method ?string getInvoicedBy()
 * @method void setInvoicedBy(?string $uid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 */
class TimeEntry extends Entity implements JsonSerializable {
	protected $ticketId;
	protected $uid;
	protected $minutes;
	protected $billable;
	protected $note;
	protected $workDate;
	protected $rate;
	protected $currency;
	protected $invoicedAt;
	protected $invoicedBy;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('ticketId', Types::BIGINT);
		$this->addType('minutes', Types::INTEGER);
		$this->addType('billable', Types::BOOLEAN);
		$this->addType('rate', Types::INTEGER);
		$this->addType('invoicedAt', Types::BIGINT);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	public function isBillable(): bool {
		return (bool)$this->billable;
	}

	/** Price of this entry in minor units, or null without a rate. */
	public function getAmount(): ?int {
		if (!$this->isBillable() || $this->rate === null) {
			return null;
		}
		return (int)round($this->minutes * $this->rate / 60);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'ticketId' => $this->ticketId,
			'uid' => $this->uid,
			'minutes' => $this->minutes,
			'billable' => $this->isBillable(),
			'note' => $this->note,
			'workDate' => $this->workDate,
			'rate' => $this->rate,
			'currency' => $this->currency,
			'amount' => $this->getAmount(),
			'invoicedAt' => $this->invoicedAt,
			'createdAt' => $this->createdAt,
		];
	}
}
