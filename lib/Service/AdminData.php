<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

/** Everything the admin settings page edits, in one structure. */
class AdminData {
	public function __construct(
		private SettingsService $settings,
		private TeamService $teams,
		private TagService $tags,
	) {
	}

	public function get(): array {
		return $this->settings->getAll() + [
			'teams' => $this->teams->all(),
			'defaultTeamId' => $this->teams->getDefault()->getId(),
			'tags' => $this->tags->all(),
		];
	}
}
