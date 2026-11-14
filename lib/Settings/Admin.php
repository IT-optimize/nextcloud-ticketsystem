<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Settings;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Service\AdminData;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IGroupManager;
use OCP\L10N\IFactory;
use OCP\Settings\ISettings;
use OCP\Util;

class Admin implements ISettings {
	public function __construct(
		private AdminData $data,
		private IInitialState $initialState,
		private IGroupManager $groupManager,
		private IFactory $l10nFactory,
	) {
	}

	public function getForm(): TemplateResponse {
		$groups = array_map(
			fn ($group) => ['id' => $group->getGID(), 'displayName' => $group->getDisplayName()],
			$this->groupManager->search(''),
		);
		$this->initialState->provideInitialState('admin-settings', $this->data->get());
		$this->initialState->provideInitialState('groups', array_values($groups));
		$this->initialState->provideInitialState('languages', $this->languages());
		Util::addScript(Application::APP_ID, Application::APP_ID . '-admin');
		return new TemplateResponse(Application::APP_ID, 'admin');
	}

	/** @return list<array{code: string, name: string}> */
	private function languages(): array {
		$languages = $this->l10nFactory->getLanguages();
		$list = [];
		foreach (array_merge($languages['commonLanguages'] ?? [], $languages['otherLanguages'] ?? []) as $language) {
			$list[] = ['code' => $language['code'], 'name' => $language['name']];
		}
		return $list;
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
