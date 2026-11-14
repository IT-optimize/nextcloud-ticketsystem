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
 * A customer company: time on its tickets is invoiced to it. Tickets from its
 * email domains are linked to it automatically.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getDomains()
 * @method void setDomains(?string $json)
 * @method ?int getHourlyRate()
 * @method void setHourlyRate(?int $minorUnits)
 * @method ?string getNotes()
 * @method void setNotes(?string $notes)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $ts)
 */
class Company extends Entity implements JsonSerializable {
	protected $name;
	protected $domains;
	protected $hourlyRate;
	protected $notes;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('hourlyRate', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
	}

	/** @return string[] lowercase domains */
	public function getDomainList(): array {
		$list = json_decode($this->domains ?? '[]', true);
		return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
	}

	/** @param string[] $domains */
	public function setDomainList(array $domains): void {
		$this->setDomains(json_encode(array_values(array_unique($domains))));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'domains' => $this->getDomainList(),
			'hourlyRate' => $this->hourlyRate,
			'notes' => $this->notes,
		];
	}
}
