<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Exception\ValidationException;
use OCA\TicketSystem\Mail\ImapClient;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\L10N\IFactory;

/**
 * Admin-configurable settings. Statuses and priorities are lists of
 * {key, label} so new ones can be added without code changes; a status with
 * "closed": true counts as done (hidden from "open" views and the dashboard).
 */
class SettingsService {
	public const DEFAULT_STATUSES = [
		['key' => 'new', 'label' => 'New', 'closed' => false],
		['key' => 'open', 'label' => 'Open', 'closed' => false],
		['key' => 'pending', 'label' => 'Waiting for customer', 'closed' => false],
		['key' => 'resolved', 'label' => 'Resolved', 'closed' => true],
		['key' => 'closed', 'label' => 'Closed', 'closed' => true],
	];

	public const DEFAULT_PRIORITIES = [
		['key' => 'low', 'label' => 'Low'],
		['key' => 'normal', 'label' => 'Normal'],
		['key' => 'high', 'label' => 'High'],
		['key' => 'urgent', 'label' => 'Urgent'],
	];

	public function __construct(
		private IAppConfig $appConfig,
		private IGroupManager $groupManager,
		private IFactory $l10nFactory,
	) {
	}

	/**
	 * A list setting or its defaults. Built-in names an admin left unchanged
	 * are translated into the current language, so Danish and English users
	 * each read their own; names an admin wrote stay as written.
	 *
	 * @param bool $translate false for the settings page, which edits the stored names
	 */
	private function optionList(string $key, array $defaults, bool $translate = true): array {
		$options = $this->appConfig->hasKey(Application::APP_ID, $key)
			? $this->appConfig->getValueArray(Application::APP_ID, $key, $defaults)
			: $defaults;
		if (!$translate) {
			return $options;
		}
		$builtIn = array_column($defaults, 'label', 'key');
		$l = $this->l10nFactory->get(Application::APP_ID);
		return array_map(
			fn (array $option) => (($builtIn[$option['key'] ?? ''] ?? null) === ($option['label'] ?? null) ? ['label' => $l->t($option['label'])] : []) + $option,
			$options,
		);
	}

	/** Groups whose members are ticketsystem agents. Admins are always agents. */
	public function getAgentGroups(): array {
		return $this->appConfig->getValueArray(Application::APP_ID, 'agent_groups', ['admin']);
	}

	/** @return list<array{key: string, label: string, closed: bool}> */
	public function getStatuses(): array {
		return $this->optionList('statuses', self::DEFAULT_STATUSES);
	}

	/** @return list<array{key: string, label: string}> */
	public function getPriorities(): array {
		return $this->optionList('priorities', self::DEFAULT_PRIORITIES);
	}

	/** @return string[] */
	public function getStatusKeys(): array {
		return array_column($this->getStatuses(), 'key');
	}

	/** @return string[] statuses that count as not done */
	public function getOpenStatusKeys(): array {
		return array_values(array_map(
			fn (array $s) => $s['key'],
			array_filter($this->getStatuses(), fn (array $s) => empty($s['closed'])),
		));
	}

	/** @return string[] */
	public function getClosedStatusKeys(): array {
		return array_values(array_diff($this->getStatusKeys(), $this->getOpenStatusKeys()));
	}

	public function getStatusLabel(string $key): string {
		foreach ($this->getStatuses() as $status) {
			if ($status['key'] === $key) {
				return $status['label'];
			}
		}
		return $key;
	}

	public function getDefaultStatus(): string {
		return $this->getStatusKeys()[0] ?? 'new';
	}

	public function getDefaultPriority(): string {
		$keys = array_column($this->getPriorities(), 'key');
		return in_array('normal', $keys, true) ? 'normal' : ($keys[0] ?? 'normal');
	}

	/** Whether public agent replies are emailed to the customer. */
	public function sendCustomerEmails(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'send_customer_emails', true);
	}

	/** Reply-To address on outgoing customer mail, normally the support inbox. */
	public function getSupportAddress(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'support_address', '');
	}

	/** The sender name set on the Email tab; empty means "use the company name". */
	public function getSenderName(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'sender_name', '');
	}

	/** Whether new tickets from email get an automatic "we received it" reply. */
	public function sendAcknowledgment(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'send_acknowledgment', true);
	}

	/**
	 * Language of customer emails and the portal's texts when a
	 * brand has none of its own. Danish unless an admin chose otherwise.
	 */
	public function customerLanguage(): string {
		return $this->appConfig->getValueString(Application::APP_ID, 'customer_language', 'da') ?: 'da';
	}

	/** Erase customer data of tickets done this many months ago; 0 = never. */
	public function retentionMonths(): int {
		return max(0, $this->appConfig->getValueInt(Application::APP_ID, 'retention_months', 0));
	}

	/** Whether customers can follow their ticket on a private web page. */
	public function portalEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'portal_enabled', true);
	}

	// --- Email intake -------------------------------------------------------

	public function intakeEnabled(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'intake_enabled', false);
	}

	/**
	 * Connection settings. The password is stored encrypted (sensitive app
	 * config) and never sent to the browser.
	 *
	 * @return array{host: string, port: int, security: string, verifyTls: bool, user: string, password: string, folder: string, processedFolder: string}
	 */
	public function getImapConfig(): array {
		return [
			'host' => $this->appConfig->getValueString(Application::APP_ID, 'imap_host', ''),
			'port' => $this->appConfig->getValueInt(Application::APP_ID, 'imap_port', 993),
			'security' => $this->appConfig->getValueString(Application::APP_ID, 'imap_security', ImapClient::SECURITY_SSL),
			'verifyTls' => $this->appConfig->getValueBool(Application::APP_ID, 'imap_verify_tls', true),
			'user' => $this->appConfig->getValueString(Application::APP_ID, 'imap_user', ''),
			'password' => $this->appConfig->getValueString(Application::APP_ID, 'imap_password', ''),
			'folder' => $this->appConfig->getValueString(Application::APP_ID, 'imap_folder', 'INBOX'),
			'processedFolder' => $this->appConfig->getValueString(Application::APP_ID, 'imap_processed_folder', ''),
		];
	}

	/**
	 * Where the main inbox was read up to (see MailIntakeService::readInbox).
	 *
	 * @return array{lastUid: ?int, uidValidity: ?int}
	 */
	public function getIntakePosition(): array {
		$uid = $this->appConfig->getValueInt(Application::APP_ID, 'intake_last_uid', 0, true);
		$validity = $this->appConfig->getValueInt(Application::APP_ID, 'intake_uid_validity', 0, true);
		return ['lastUid' => $uid ?: null, 'uidValidity' => $validity ?: null];
	}

	/** @param array{lastUid: ?int, uidValidity: ?int} $position */
	public function setIntakePosition(array $position): void {
		$this->appConfig->setValueInt(Application::APP_ID, 'intake_last_uid', (int)$position['lastUid'], true);
		$this->appConfig->setValueInt(Application::APP_ID, 'intake_uid_validity', (int)$position['uidValidity'], true);
	}

	/** Result of the last intake run, shown on the settings page. */
	public function recordIntakeRun(?string $error, int $imported): void {
		$this->appConfig->setValueInt(Application::APP_ID, 'intake_last_run', time(), true);
		$this->appConfig->setValueString(Application::APP_ID, 'intake_last_error', $error ?? '', true);
		if ($error === null) {
			$this->appConfig->setValueInt(Application::APP_ID, 'intake_last_success', time(), true);
		}
		if ($imported > 0) {
			$total = $this->appConfig->getValueInt(Application::APP_ID, 'intake_imported_total', 0, true);
			$this->appConfig->setValueInt(Application::APP_ID, 'intake_imported_total', $total + $imported, true);
		}
	}

	public function getIntakeStatus(): array {
		return [
			'lastRun' => $this->appConfig->getValueInt(Application::APP_ID, 'intake_last_run', 0, true) ?: null,
			'lastSuccess' => $this->appConfig->getValueInt(Application::APP_ID, 'intake_last_success', 0, true) ?: null,
			'lastError' => $this->appConfig->getValueString(Application::APP_ID, 'intake_last_error', '', true) ?: null,
			'importedTotal' => $this->appConfig->getValueInt(Application::APP_ID, 'intake_imported_total', 0, true),
		];
	}

	public function getAll(): array {
		$imap = $this->getImapConfig();
		return [
			'agentGroups' => $this->getAgentGroups(),
			'statuses' => $this->optionList('statuses', self::DEFAULT_STATUSES, false),
			'priorities' => $this->optionList('priorities', self::DEFAULT_PRIORITIES, false),
			'sendCustomerEmails' => $this->sendCustomerEmails(),
			'sendAcknowledgment' => $this->sendAcknowledgment(),
			'portalEnabled' => $this->portalEnabled(),
			'retentionMonths' => $this->retentionMonths(),
			'customerLanguage' => $this->customerLanguage(),
			'supportAddress' => $this->getSupportAddress(),
			'senderName' => $this->getSenderName(),
			'intakeEnabled' => $this->intakeEnabled(),
			'imapHost' => $imap['host'],
			'imapPort' => $imap['port'],
			'imapSecurity' => $imap['security'],
			'imapVerifyTls' => $imap['verifyTls'],
			'imapUser' => $imap['user'],
			'imapPasswordSet' => $imap['password'] !== '',
			'imapFolder' => $imap['folder'],
			'imapProcessedFolder' => $imap['processedFolder'],
			'intakeStatus' => $this->getIntakeStatus(),
		];
	}

	/**
	 * IMAP settings from a settings form, merged over the stored ones, for the
	 * connection test (which must work before anything is saved).
	 */
	public function imapConfigFromInput(array $values): array {
		$config = $this->getImapConfig();
		$map = ['imapHost' => 'host', 'imapPort' => 'port', 'imapSecurity' => 'security', 'imapVerifyTls' => 'verifyTls',
			'imapUser' => 'user', 'imapFolder' => 'folder', 'imapProcessedFolder' => 'processedFolder'];
		foreach ($map as $input => $key) {
			if (array_key_exists($input, $values)) {
				$config[$key] = $values[$input];
			}
		}
		if (isset($values['imapPassword']) && $values['imapPassword'] !== '') {
			$config['password'] = (string)$values['imapPassword'];
		}
		return $this->validateImap($config);
	}

	/**
	 * Checks and normalises IMAP settings (server name, port, security,
	 * folders). Also used for brands' own inboxes.
	 *
	 * @throws ValidationException
	 */
	public function validateImap(array $config): array {
		$config['host'] = trim((string)$config['host']);
		if ($config['host'] !== '' && !preg_match('/^[a-z0-9.-]+$|^\[[0-9a-f:.]+\]$/i', $config['host'])) {
			throw new ValidationException('The mail server name is not valid');
		}
		$config['port'] = (int)$config['port'];
		if ($config['port'] < 1 || $config['port'] > 65535) {
			throw new ValidationException('The port must be between 1 and 65535');
		}
		if (!in_array($config['security'], [ImapClient::SECURITY_SSL, ImapClient::SECURITY_STARTTLS, ImapClient::SECURITY_NONE], true)) {
			throw new ValidationException('Unknown connection security');
		}
		$config['verifyTls'] = (bool)$config['verifyTls'];
		$config['user'] = trim((string)$config['user']);
		$config['folder'] = trim((string)$config['folder']) ?: 'INBOX';
		$config['processedFolder'] = trim((string)$config['processedFolder']);
		if ($config['processedFolder'] !== '' && strcasecmp($config['processedFolder'], $config['folder']) === 0) {
			throw new ValidationException('The folder for processed mail must differ from the inbox folder');
		}
		return $config;
	}

	/**
	 * Apply a partial update from the admin settings page.
	 * @throws ValidationException
	 */
	public function update(array $values): array {
		if (array_key_exists('agentGroups', $values)) {
			$groups = array_values(array_filter(array_map('strval', (array)$values['agentGroups'])));
			foreach ($groups as $gid) {
				if (!$this->groupManager->groupExists($gid)) {
					throw new ValidationException('Unknown group: ' . $gid);
				}
			}
			$this->appConfig->setValueArray(Application::APP_ID, 'agent_groups', $groups);
		}
		if (array_key_exists('statuses', $values)) {
			$statuses = $this->normalizeOptions((array)$values['statuses'], true);
			if (count(array_filter($statuses, fn ($s) => !$s['closed'])) === 0) {
				throw new ValidationException('At least one status must be open');
			}
			$this->appConfig->setValueArray(Application::APP_ID, 'statuses', $statuses);
		}
		if (array_key_exists('priorities', $values)) {
			$this->appConfig->setValueArray(Application::APP_ID, 'priorities', $this->normalizeOptions((array)$values['priorities'], false));
		}
		if (array_key_exists('sendCustomerEmails', $values)) {
			$this->appConfig->setValueBool(Application::APP_ID, 'send_customer_emails', (bool)$values['sendCustomerEmails']);
		}
		if (array_key_exists('supportAddress', $values)) {
			$address = trim((string)$values['supportAddress']);
			if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
				throw new ValidationException('Support address is not a valid email address');
			}
			$this->appConfig->setValueString(Application::APP_ID, 'support_address', $address);
		}
		if (array_key_exists('senderName', $values)) {
			$this->appConfig->setValueString(Application::APP_ID, 'sender_name', trim((string)$values['senderName']));
		}
		if (array_key_exists('customerLanguage', $values)) {
			$language = (string)$values['customerLanguage'];
			if (!preg_match('/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/', $language)) {
				throw new ValidationException('Unknown language');
			}
			$this->appConfig->setValueString(Application::APP_ID, 'customer_language', $language);
		}
		if (array_key_exists('retentionMonths', $values)) {
			$months = (int)$values['retentionMonths'];
			if ($months < 0 || $months > 240) {
				throw new ValidationException('Keep customer data for 0 (always) to 240 months');
			}
			$this->appConfig->setValueInt(Application::APP_ID, 'retention_months', $months);
		}
		foreach (['sendAcknowledgment' => 'send_acknowledgment', 'portalEnabled' => 'portal_enabled'] as $input => $key) {
			if (array_key_exists($input, $values)) {
				$this->appConfig->setValueBool(Application::APP_ID, $key, (bool)$values[$input]);
			}
		}

		$imapKeys = ['imapHost', 'imapPort', 'imapSecurity', 'imapVerifyTls', 'imapUser', 'imapPassword', 'imapFolder', 'imapProcessedFolder'];
		if (array_intersect($imapKeys, array_keys($values)) !== []) {
			$imap = $this->imapConfigFromInput($values);
			$this->appConfig->setValueString(Application::APP_ID, 'imap_host', $imap['host']);
			$this->appConfig->setValueInt(Application::APP_ID, 'imap_port', $imap['port']);
			$this->appConfig->setValueString(Application::APP_ID, 'imap_security', $imap['security']);
			$this->appConfig->setValueBool(Application::APP_ID, 'imap_verify_tls', $imap['verifyTls']);
			$this->appConfig->setValueString(Application::APP_ID, 'imap_user', $imap['user']);
			$this->appConfig->setValueString(Application::APP_ID, 'imap_password', $imap['password'], false, true);
			$this->appConfig->setValueString(Application::APP_ID, 'imap_folder', $imap['folder']);
			$this->appConfig->setValueString(Application::APP_ID, 'imap_processed_folder', $imap['processedFolder']);
		}
		if (array_key_exists('intakeEnabled', $values)) {
			$enable = (bool)$values['intakeEnabled'];
			$imap = $this->getImapConfig();
			if ($enable && ($imap['host'] === '' || $imap['user'] === '' || $imap['password'] === '')) {
				throw new ValidationException('Fill in the mail server, user name and password before turning on email intake');
			}
			$this->appConfig->setValueBool(Application::APP_ID, 'intake_enabled', $enable);
		}
		return $this->getAll();
	}

	private function normalizeOptions(array $options, bool $withClosed): array {
		$result = [];
		$seen = [];
		foreach ($options as $option) {
			$key = strtolower(trim((string)($option['key'] ?? '')));
			$label = trim((string)($option['label'] ?? ''));
			if (!preg_match('/^[a-z0-9_-]{1,32}$/', $key)) {
				throw new ValidationException('Keys must be 1-32 characters: a-z, 0-9, _ or -');
			}
			if (isset($seen[$key])) {
				throw new ValidationException('Duplicate key: ' . $key);
			}
			$seen[$key] = true;
			$entry = ['key' => $key, 'label' => $label !== '' ? $label : $key];
			if ($withClosed) {
				$entry['closed'] = !empty($option['closed']);
			}
			$result[] = $entry;
		}
		if ($result === []) {
			throw new ValidationException('The list cannot be empty');
		}
		return $result;
	}
}
