<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Which agents have a ticket open right now, and whether they are writing,
 * so two agents don't answer the same customer at once.
 *
 * Kept in the distributed cache (Redis in most setups) with a short expiry.
 * Without a distributed cache presence is simply never shown.
 */
class PresenceService {
	public const VIEWING = 'viewing';
	public const TYPING = 'typing';
	public const LEFT = 'left';

	/** An agent counts as present this many seconds after their last heartbeat. */
	private const STALE_AFTER = 40;

	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private ITimeFactory $time,
	) {
		$this->cache = $cacheFactory->createDistributed('ticketsystem-presence');
	}

	/**
	 * Record this agent's state on a ticket.
	 *
	 * @return list<array{uid: string, state: string}> the other agents present
	 */
	public function update(int $ticketId, string $uid, string $state): array {
		$key = (string)$ticketId;
		$now = $this->time->getTime();
		$entries = $this->fresh($this->cache->get($key), $now);
		if ($state === self::LEFT) {
			unset($entries[$uid]);
		} else {
			$entries[$uid] = ['state' => $state === self::TYPING ? self::TYPING : self::VIEWING, 'at' => $now];
		}
		if ($entries === []) {
			$this->cache->remove($key);
		} else {
			$this->cache->set($key, $entries, self::STALE_AFTER * 2);
		}

		$others = [];
		foreach ($entries as $otherUid => $entry) {
			if ((string)$otherUid !== $uid) {
				$others[] = ['uid' => (string)$otherUid, 'state' => $entry['state']];
			}
		}
		return $others;
	}

	/** @return array<string, array{state: string, at: int}> */
	private function fresh(mixed $stored, int $now): array {
		if (!is_array($stored)) {
			return [];
		}
		return array_filter($stored, fn ($entry) => is_array($entry)
			&& isset($entry['state'], $entry['at'])
			&& $entry['at'] >= $now - self::STALE_AFTER);
	}
}
