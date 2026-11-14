<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\AppInfo\Application;
use OCA\TicketSystem\Db\Team;
use OCA\TicketSystem\Db\TeamMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Util;

/**
 * Brands (teams). Agents are the members of a brand's groups and see only
 * that brand's tickets; Nextcloud admins see every brand.
 */
class TeamService {
	public const COLORS = ['#0082c9', '#2e7d32', '#c62828', '#6a1b9a', '#ef6c00', '#00838f', '#ad1457', '#4e342e'];

	/** @var Team[]|null */
	private ?array $cache = null;
	/** @var array<string, int[]|null> */
	private array $idsFor = [];

	public function __construct(
		private TeamMapper $mapper,
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private IAppConfig $appConfig,
		private SettingsService $settings,
		private ITimeFactory $time,
		private ICrypto $crypto,
		private Defaults $defaults,
		private IConfig $config,
	) {
	}

	/**
	 * A first guess at the customer's company name, until the admin sets it:
	 * the name given to Nextcloud under Theming, else the server's mail domain
	 * ("firma.dk" → "Firma"), else the Nextcloud name itself.
	 */
	public function suggestedCompanyName(): string {
		$themed = trim($this->defaults->getName());
		if ($themed !== '' && strcasecmp($themed, 'Nextcloud') !== 0) {
			return $themed;
		}
		$labels = explode('.', strtolower(trim($this->config->getSystemValueString('mail_domain', ''))));
		if (count($labels) >= 2) {
			$name = $labels[count($labels) - 2];
			if (preg_match('/^[\p{L}\d-]{2,}$/u', $name) && !in_array($name, ['localhost', 'example', 'local', 'test'], true)) {
				return mb_convert_case($name, MB_CASE_TITLE);
			}
		}
		return $themed !== '' ? $themed : 'Nextcloud';
	}

	/** The name customer emails come from: the Email tab's sender name, else the company name. */
	public function senderNameFor(Team $team): string {
		return $team->getSenderName() ?? ($this->settings->getSenderName() ?: $team->getName());
	}

	/** Whether an admin has saved the company once (until then Ticket system asks for it). */
	public function isCompanyConfirmed(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, 'company_confirmed', false);
	}

	public function confirmCompany(): void {
		$this->appConfig->setValueBool(Application::APP_ID, 'company_confirmed', true);
	}

	/** @return Team[] every brand, the first one created on first use */
	public function all(): array {
		if ($this->cache === null) {
			$this->cache = $this->mapper->findAll();
			if ($this->cache === []) {
				$this->cache = [$this->ensureDefault()];
			}
		}
		return $this->cache;
	}

	/** @throws NotFoundException */
	public function get(int $id): Team {
		foreach ($this->all() as $team) {
			if ($team->getId() === $id) {
				return $team;
			}
		}
		throw new NotFoundException('Brand not found');
	}

	public function find(?int $id): ?Team {
		if ($id === null) {
			return null;
		}
		try {
			return $this->get($id);
		} catch (NotFoundException) {
			return null;
		}
	}

	/** The brand new tickets go to when nothing else decides (email intake, the API). */
	public function getDefault(): Team {
		$teams = $this->all();
		$id = $this->appConfig->getValueInt(Application::APP_ID, 'default_team', 0);
		foreach ($teams as $team) {
			if ($team->getId() === $id) {
				return $team;
			}
		}
		return $teams[0];
	}

	/**
	 * The first brand, made from the settings Ticket system used before brands
	 * existed. Does nothing when a brand exists already.
	 */
	public function ensureDefault(): Team {
		$existing = $this->mapper->findAll();
		if ($existing !== []) {
			return $existing[0];
		}
		$team = new Team();
		$senderName = $this->settings->getSenderName();
		$team->setName(mb_substr($senderName !== '' ? $senderName : $this->suggestedCompanyName(), 0, 100));
		$team->setGroupIds($this->settings->getAgentGroups());
		$replyTo = $this->settings->getSupportAddress();
		$team->setReplyTo($replyTo !== '' ? $replyTo : null);
		$team->setColor(self::COLORS[0]);
		$team->setSortOrder(0);
		$team->setCreatedAt($this->time->getTime());
		$team = $this->mapper->insert($team);
		$this->appConfig->setValueInt(Application::APP_ID, 'default_team', $team->getId());
		$this->cache = null;
		return $team;
	}

	/**
	 * Addresses that are Ticket system itself: the support inbox, the sender
	 * address and every brand's reply address, lower-cased. Mail from them is
	 * never imported, and they are never copied on customer emails.
	 *
	 * @return string[]
	 */
	public function ownAddresses(): array {
		$own = [
			$this->settings->getSupportAddress(),
			Util::getDefaultEmailAddress('noreply'),
			$this->settings->getImapConfig()['user'],
		];
		foreach ($this->all() as $team) {
			$own[] = (string)$team->getReplyTo();
			$own[] = (string)($team->getInboxConfig()['user'] ?? '');
		}
		return array_values(array_unique(array_filter(array_map(fn ($a) => strtolower(trim($a)), $own))));
	}

	/** Whether this user works on at least one brand (or is an admin). */
	public function isAgent(?string $uid): bool {
		if ($uid === null || $uid === '') {
			return false;
		}
		$ids = $this->teamIdsFor($uid);
		return $ids === null || $ids !== [];
	}

	/**
	 * The brands this user works on; null means all of them (admins).
	 *
	 * @return int[]|null
	 */
	public function teamIdsFor(string $uid): ?array {
		if (!array_key_exists($uid, $this->idsFor)) {
			if ($this->groupManager->isAdmin($uid)) {
				$this->idsFor[$uid] = null;
			} else {
				$user = $this->userManager->get($uid);
				$userGroups = $user !== null && $user->isEnabled() ? $this->groupManager->getUserGroupIds($user) : [];
				$ids = [];
				foreach ($this->all() as $team) {
					if (array_intersect($team->getGroupIds(), $userGroups) !== []) {
						$ids[] = $team->getId();
					}
				}
				$this->idsFor[$uid] = $ids;
			}
		}
		return $this->idsFor[$uid];
	}

	public function canAccess(string $uid, ?int $teamId): bool {
		$ids = $this->teamIdsFor($uid);
		return $ids === null || $teamId === null || in_array($teamId, $ids, true);
	}

	/** @return Team[] the brands this user works on */
	public function teamsFor(string $uid): array {
		$ids = $this->teamIdsFor($uid);
		return array_values(array_filter($this->all(), fn (Team $t) => $ids === null || in_array($t->getId(), $ids, true)));
	}

	/**
	 * @param array{name?: string, agentGroups?: string[], senderName?: ?string, replyTo?: ?string, signature?: ?string, color?: ?string, language?: ?string, isDefault?: bool} $data
	 * @throws ValidationException
	 */
	public function create(array $data): Team {
		$team = new Team();
		$team->setSortOrder(count($this->all()));
		$team->setColor(self::COLORS[count($this->all()) % count(self::COLORS)]);
		$team->setCreatedAt($this->time->getTime());
		$this->apply($team, $data + ['agentGroups' => []], true);
		$team = $this->mapper->insert($team);
		if (!empty($data['isDefault'])) {
			$this->appConfig->setValueInt(Application::APP_ID, 'default_team', $team->getId());
		}
		$this->reset();
		return $team;
	}

	/** @throws NotFoundException|ValidationException */
	public function update(int $id, array $data): Team {
		$team = $this->get($id);
		$this->apply($team, $data, false);
		$team = $this->mapper->update($team);
		if (!empty($data['isDefault'])) {
			$this->appConfig->setValueInt(Application::APP_ID, 'default_team', $team->getId());
		}
		$this->reset();
		return $team;
	}

	/**
	 * Brands whose own inbox is switched on, with the settings ready to
	 * connect (password decrypted).
	 *
	 * @return list<array{team: Team, config: array}>
	 */
	public function inboxes(): array {
		$list = [];
		foreach ($this->all() as $team) {
			$config = $team->getInboxConfig();
			if ($config !== null && $config['enabled']) {
				$list[] = ['team' => $team, 'config' => $this->decryptInbox($config)];
			}
		}
		return $list;
	}

	/**
	 * A brand's inbox settings from the form merged over the stored ones,
	 * for the connection test, which must work before anything is saved.
	 *
	 * @throws NotFoundException|ValidationException
	 */
	public function inboxForTest(?int $teamId, array $input): array {
		$stored = $teamId !== null ? $this->get($teamId)->getInboxConfig() : null;
		$config = $this->settings->validateImap([
			'host' => $input['host'] ?? $stored['host'] ?? '',
			'port' => $input['port'] ?? $stored['port'] ?? 993,
			'security' => $input['security'] ?? $stored['security'] ?? 'ssl',
			'verifyTls' => $input['verifyTls'] ?? $stored['verifyTls'] ?? true,
			'user' => $input['user'] ?? $stored['user'] ?? '',
			'folder' => $input['folder'] ?? $stored['folder'] ?? 'INBOX',
			'processedFolder' => $input['processedFolder'] ?? $stored['processedFolder'] ?? '',
		]);
		$config['password'] = ($input['password'] ?? '') !== ''
			? (string)$input['password']
			: ($stored !== null ? $this->decryptInbox($stored)['password'] : '');
		return $config;
	}

	/**
	 * How the brand's last inbox check went, for its settings, and where it
	 * read up to.
	 *
	 * @param array{lastUid: ?int, uidValidity: ?int} $position
	 */
	public function recordInboxRun(Team $team, ?string $error, int $imported, array $position): void {
		$status = array_merge($team->getInboxStatusData(), $position);
		$now = $this->time->getTime();
		$status['lastRun'] = $now;
		$status['lastError'] = $error;
		if ($error === null) {
			$status['lastSuccess'] = $now;
			$status['importedTotal'] += $imported;
		}
		$team->setInboxStatus(json_encode($status));
		$this->mapper->update($team);
	}

	/**
	 * @return string|null JSON for the inbox column, null for no inbox
	 * @throws ValidationException
	 */
	private function cleanInbox(Team $team, mixed $input): ?string {
		$input = is_array($input) ? $input : [];
		if (trim((string)($input['host'] ?? '')) === '') {
			if (!empty($input['enabled'])) {
				throw new ValidationException('Fill in the mail server before switching on the brand\'s inbox');
			}
			return null;
		}
		$previous = $team->getInboxConfig();
		$config = $this->settings->validateImap([
			'host' => $input['host'],
			'port' => $input['port'] ?? 993,
			'security' => $input['security'] ?? 'ssl',
			'verifyTls' => $input['verifyTls'] ?? true,
			'user' => $input['user'] ?? '',
			'folder' => $input['folder'] ?? 'INBOX',
			'processedFolder' => $input['processedFolder'] ?? '',
		]);
		$config['password'] = ($input['password'] ?? '') !== ''
			? $this->crypto->encrypt((string)$input['password'])
			: ($previous['password'] ?? '');
		$config['enabled'] = !empty($input['enabled']);
		if ($config['enabled'] && ($config['user'] === '' || $config['password'] === '')) {
			throw new ValidationException('Fill in the user name and password before switching on the brand\'s inbox');
		}
		$mailbox = strtolower($config['user'] . '@' . $config['host']);
		$global = $this->settings->getImapConfig();
		$taken = $global['host'] !== '' && strtolower($global['user'] . '@' . $global['host']) === $mailbox;
		foreach ($this->all() as $other) {
			$theirs = $other->getInboxConfig();
			if ($other->getId() !== $team->getId() && $theirs !== null && strtolower($theirs['user'] . '@' . $theirs['host']) === $mailbox) {
				$taken = true;
			}
		}
		if ($taken) {
			throw new ValidationException('Another inbox in Ticket system already reads this mailbox');
		}
		return json_encode($config);
	}

	private function decryptInbox(array $config): array {
		try {
			$config['password'] = $config['password'] !== '' ? $this->crypto->decrypt($config['password']) : '';
		} catch (\Throwable) {
			// Encrypted with another secret (e.g. restored from elsewhere): ask for it again.
			$config['password'] = '';
		}
		return $config;
	}

	/**
	 * Deletes a brand. Its tickets move to $moveTo (required when it has any).
	 *
	 * @throws NotFoundException|ValidationException
	 */
	public function delete(int $id, ?int $moveTo): void {
		$team = $this->get($id);
		if (count($this->all()) === 1) {
			throw new ValidationException('Ticket system needs at least one brand');
		}
		if ($this->mapper->countTickets($id) > 0) {
			if ($moveTo === null || $moveTo === $id) {
				throw new ValidationException('This brand has tickets. Choose a brand to move them to.');
			}
			$this->get($moveTo);
		}
		if ($moveTo !== null) {
			$this->mapper->moveTickets($id, $moveTo);
		}
		$this->mapper->delete($team);
		if ($this->getDefault()->getId() === $id) {
			$this->appConfig->deleteKey(Application::APP_ID, 'default_team');
		}
		$this->reset();
	}

	private function apply(Team $team, array $data, bool $isNew): void {
		if ($isNew || array_key_exists('name', $data)) {
			$name = trim(preg_replace('/\s+/u', ' ', (string)($data['name'] ?? '')) ?? '');
			if ($name === '') {
				throw new ValidationException('A brand needs a name');
			}
			$team->setName(mb_substr($name, 0, 100));
		}
		if (array_key_exists('agentGroups', $data)) {
			$groups = array_values(array_filter(array_map('strval', (array)$data['agentGroups'])));
			foreach ($groups as $gid) {
				if (!$this->groupManager->groupExists($gid)) {
					throw new ValidationException('Unknown group: ' . $gid);
				}
			}
			$team->setGroupIds($groups);
		}
		if (array_key_exists('senderName', $data)) {
			$team->setSenderName($this->optional($data['senderName'], 255));
		}
		if (array_key_exists('replyTo', $data)) {
			$address = $this->optional($data['replyTo'], 255);
			if ($address !== null && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
				throw new ValidationException('The reply address is not a valid email address');
			}
			$team->setReplyTo($address);
		}
		if (array_key_exists('signature', $data)) {
			$team->setSignature($this->optional($data['signature'], 2000));
		}
		if (array_key_exists('color', $data)) {
			$color = $this->optional($data['color'], 16);
			if ($color !== null && !preg_match('/^#[0-9a-f]{6}$/i', $color)) {
				throw new ValidationException('Colors are written like #0082c9');
			}
			$team->setColor($color);
		}
		if (array_key_exists('hourlyRate', $data)) {
			$team->setHourlyRate(self::cleanRate($data['hourlyRate']));
		}
		if (array_key_exists('currency', $data)) {
			$currency = strtoupper(trim((string)($data['currency'] ?? '')));
			if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
				throw new ValidationException('Currencies are written as three letters, like DKK or EUR');
			}
			$team->setCurrency($currency !== '' ? $currency : null);
		}
		if (array_key_exists('inbox', $data)) {
			$team->setInbox($this->cleanInbox($team, $data['inbox']));
		}
		if (array_key_exists('language', $data)) {
			$language = $this->optional($data['language'], 16);
			if ($language !== null && !preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $language)) {
				throw new ValidationException('Unknown language');
			}
			$team->setLanguage($language);
		}
	}

	/**
	 * A price per hour from the API, in minor units (øre, cents), or null.
	 *
	 * @throws ValidationException
	 */
	public static function cleanRate(mixed $rate): ?int {
		if ($rate === null || $rate === '') {
			return null;
		}
		if (!is_numeric($rate) || (int)$rate < 0 || (int)$rate > 100_000_000) {
			throw new ValidationException('The hourly rate must be a positive amount');
		}
		return (int)$rate;
	}

	private function optional(mixed $value, int $max): ?string {
		$value = trim((string)($value ?? ''));
		return $value === '' ? null : mb_substr($value, 0, $max);
	}

	private function reset(): void {
		$this->cache = null;
		$this->idsFor = [];
	}
}
