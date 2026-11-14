<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\CannedReply;
use OCA\TicketSystem\Db\CannedReplyMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Canned replies. Shared ones belong to the whole team: any agent may edit
 * or delete them. Personal ones are visible to and editable by their owner only.
 */
class CannedReplyService {
	private const MAX_TITLE = 255;
	private const MAX_BODY = 20000;

	public function __construct(
		private CannedReplyMapper $mapper,
		private TeamService $teams,
		private ITimeFactory $time,
	) {
	}

	/** @return CannedReply[] shared replies of the agent's brands, plus their own */
	public function list(string $uid): array {
		return array_values(array_filter(
			$this->mapper->findVisibleTo($uid),
			fn (CannedReply $r) => $this->teams->canAccess($uid, $r->getTeamId()),
		));
	}

	/**
	 * @param int|null $teamId null = for every brand
	 * @throws ValidationException
	 */
	public function create(string $uid, string $title, string $body, bool $shared, ?int $teamId = null): CannedReply {
		$reply = new CannedReply();
		$reply->setTeamId($this->cleanTeam($uid, $teamId));
		$reply->setTitle($this->cleanTitle($title));
		$reply->setBody($this->cleanBody($body));
		$reply->setOwnerUid($shared ? null : $uid);
		$reply->setCreatedBy($uid);
		$reply->setUsageCount(0);
		$now = $this->time->getTime();
		$reply->setCreatedAt($now);
		$reply->setUpdatedAt($now);
		return $this->mapper->insert($reply);
	}

	/** @throws NotFoundException|ValidationException */
	public function update(string $uid, int $id, ?string $title, ?string $body, ?bool $shared, bool $setTeam = false, ?int $teamId = null): CannedReply {
		// Anyone who can see a reply may edit it: shared replies belong to the team.
		$reply = $this->getVisible($uid, $id);
		if ($title !== null) {
			$reply->setTitle($this->cleanTitle($title));
		}
		if ($body !== null) {
			$reply->setBody($this->cleanBody($body));
		}
		if ($shared !== null) {
			$reply->setOwnerUid($shared ? null : $uid);
		}
		if ($setTeam) {
			$reply->setTeamId($this->cleanTeam($uid, $teamId));
		}
		$reply->setUpdatedAt($this->time->getTime());
		return $this->mapper->update($reply);
	}

	/** @throws NotFoundException */
	public function delete(string $uid, int $id): void {
		$this->mapper->delete($this->getVisible($uid, $id));
	}

	/** @throws NotFoundException */
	public function recordUse(string $uid, int $id): void {
		$this->getVisible($uid, $id);
		$this->mapper->incrementUsage($id);
	}

	/** @throws NotFoundException */
	private function getVisible(string $uid, int $id): CannedReply {
		try {
			$reply = $this->mapper->find($id);
		} catch (DoesNotExistException) {
			throw new NotFoundException('Canned reply not found');
		}
		// Someone else's personal reply, or another brand's, looks the same as a missing one.
		if ((!$reply->isShared() && $reply->getOwnerUid() !== $uid) || !$this->teams->canAccess($uid, $reply->getTeamId())) {
			throw new NotFoundException('Canned reply not found');
		}
		return $reply;
	}

	private function cleanTeam(string $uid, ?int $teamId): ?int {
		if ($teamId === null || $teamId <= 0) {
			return null;
		}
		if ($this->teams->find($teamId) === null || !$this->teams->canAccess($uid, $teamId)) {
			throw new ValidationException('Unknown brand');
		}
		return $teamId;
	}

	private function cleanTitle(string $title): string {
		$title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
		if ($title === '') {
			throw new ValidationException('A canned reply needs a title');
		}
		return mb_substr($title, 0, self::MAX_TITLE);
	}

	private function cleanBody(string $body): string {
		$body = trim(str_replace("\r\n", "\n", $body));
		if ($body === '') {
			throw new ValidationException('A canned reply needs some text');
		}
		if (mb_strlen($body) > self::MAX_BODY) {
			throw new ValidationException('The text is too long');
		}
		return $body;
	}
}
