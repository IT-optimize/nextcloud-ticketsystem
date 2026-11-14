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
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getColor()
 * @method void setColor(?string $color)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 */
class Tag extends Entity implements JsonSerializable {
	protected $name;
	protected $color;
	protected $createdAt;

	public function __construct() {
		$this->addType('createdAt', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return ['id' => $this->getId(), 'name' => $this->name, 'color' => $this->color];
	}
}
