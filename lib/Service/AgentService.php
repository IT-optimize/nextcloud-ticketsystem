<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Exception\ForbiddenException;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Who counts as a ticketsystem agent: members of a brand's groups, plus
 * Nextcloud admins (who work on every brand).
 */
class AgentService {
	public function __construct(
		private TeamService $teams,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
	) {
	}

	public function isAgent(?string $uid): bool {
		return $this->teams->isAgent($uid);
	}

	/** @throws ForbiddenException */
	public function requireAgent(?string $uid): void {
		if (!$this->isAgent($uid)) {
			throw new ForbiddenException('Only ticketsystem agents can do this');
		}
	}

	/** Whether this user may be assigned tickets of the brand. */
	public function worksOn(string $uid, ?int $teamId): bool {
		return $this->isAgent($uid) && $this->teams->canAccess($uid, $teamId);
	}

	/**
	 * Every agent with the brands they work on (null = all brands).
	 *
	 * @return list<array{uid: string, displayName: string, teamIds: int[]|null}>
	 */
	public function listAgents(): array {
		$groups = ['admin'];
		foreach ($this->teams->all() as $team) {
			$groups = array_merge($groups, $team->getGroupIds());
		}
		$users = [];
		foreach (array_unique($groups) as $gid) {
			$group = $this->groupManager->get($gid);
			if ($group === null) {
				continue;
			}
			foreach ($group->getUsers() as $user) {
				if ($user->isEnabled()) {
					$users[$user->getUID()] = $user->getDisplayName();
				}
			}
		}
		natcasesort($users);
		$result = [];
		foreach ($users as $uid => $name) {
			$uid = (string)$uid;
			$result[] = ['uid' => $uid, 'displayName' => $name, 'teamIds' => $this->teams->teamIdsFor($uid)];
		}
		return $result;
	}

	public function getUser(string $uid): ?IUser {
		return $this->userManager->get($uid);
	}

	public function getDisplayName(?string $uid): string {
		if ($uid === null) {
			return '';
		}
		return $this->userManager->getDisplayName($uid) ?? $uid;
	}
}
