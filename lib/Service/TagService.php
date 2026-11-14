<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\RelationMapper;
use OCA\TicketSystem\Db\Tag;
use OCA\TicketSystem\Db\TagMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DbException;

/**
 * Tags, shared by all brands. Agents create them while tagging a ticket;
 * renaming, recoloring and deleting is for admins.
 */
class TagService {
	public const COLORS = ['#0082c9', '#2e7d32', '#c62828', '#6a1b9a', '#ef6c00', '#00838f', '#ad1457', '#5d4037', '#455a64', '#9e9d24'];

	/** @var Tag[]|null */
	private ?array $cache = null;

	public function __construct(
		private TagMapper $mapper,
		private RelationMapper $relations,
		private ITimeFactory $time,
	) {
	}

	/** @return Tag[] */
	public function all(): array {
		return $this->cache ??= $this->mapper->findAll();
	}

	/** @return int[] */
	public function allIds(): array {
		return array_map(fn (Tag $t) => $t->getId(), $this->all());
	}

	/**
	 * The tag with this name, created if it doesn't exist yet.
	 *
	 * @throws ValidationException
	 */
	public function findOrCreate(string $name, ?string $color = null): Tag {
		$name = $this->cleanName($name);
		$existing = $this->mapper->findByName($name);
		if ($existing !== null) {
			return $existing;
		}
		$tag = new Tag();
		$tag->setName($name);
		$tag->setColor($color !== null ? $this->cleanColor($color) : self::COLORS[crc32(mb_strtolower($name)) % count(self::COLORS)]);
		$tag->setCreatedAt($this->time->getTime());
		try {
			$tag = $this->mapper->insert($tag);
		} catch (DbException $e) {
			// Created by someone else a moment ago.
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION && ($found = $this->mapper->findByName($name)) !== null) {
				return $found;
			}
			throw $e;
		}
		$this->cache = null;
		return $tag;
	}

	/** @throws NotFoundException|ValidationException */
	public function update(int $id, ?string $name, ?string $color): Tag {
		$tag = $this->get($id);
		if ($name !== null) {
			$name = $this->cleanName($name);
			$other = $this->mapper->findByName($name);
			if ($other !== null && $other->getId() !== $id) {
				throw new ValidationException('A tag with this name exists already');
			}
			$tag->setName($name);
		}
		if ($color !== null) {
			$tag->setColor($this->cleanColor($color));
		}
		$this->cache = null;
		return $this->mapper->update($tag);
	}

	/** @throws NotFoundException */
	public function delete(int $id): void {
		$tag = $this->get($id);
		$this->relations->deleteTag($id);
		$this->mapper->delete($tag);
		$this->cache = null;
	}

	/** @throws NotFoundException */
	public function get(int $id): Tag {
		try {
			return $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Tag not found');
		}
	}

	private function cleanName(string $name): string {
		$name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
		if ($name === '') {
			throw new ValidationException('A tag needs a name');
		}
		return mb_substr($name, 0, 64);
	}

	private function cleanColor(string $color): string {
		if (!preg_match('/^#[0-9a-f]{6}$/i', $color)) {
			throw new ValidationException('Colors are written like #0082c9');
		}
		return strtolower($color);
	}
}
