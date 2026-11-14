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
 * A brand or team: its own agents (members of its groups), sender name,
 * reply address and signature. Every ticket belongs to one.
 *
 * @method string getName()
 * @method void setName(string $name)
 * @method ?string getAgentGroups()
 * @method void setAgentGroups(?string $json)
 * @method ?string getSenderName()
 * @method void setSenderName(?string $name)
 * @method ?string getReplyTo()
 * @method void setReplyTo(?string $address)
 * @method ?string getSignature()
 * @method void setSignature(?string $signature)
 * @method ?string getColor()
 * @method void setColor(?string $color)
 * @method ?string getLanguage()
 * @method void setLanguage(?string $language)
 * @method int getSortOrder()
 * @method void setSortOrder(int $order)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $ts)
 * @method ?int getHourlyRate()
 * @method void setHourlyRate(?int $minorUnits)
 * @method ?string getCurrency()
 * @method void setCurrency(?string $code)
 * @method ?string getInbox()
 * @method void setInbox(?string $json)
 * @method ?string getInboxStatus()
 * @method void setInboxStatus(?string $json)
 */
class Team extends Entity implements JsonSerializable {
	// No default values: see Ticket.
	protected $name;
	protected $agentGroups;
	protected $senderName;
	protected $replyTo;
	protected $signature;
	protected $color;
	protected $language;
	protected $sortOrder;
	protected $createdAt;
	protected $hourlyRate;
	protected $currency;
	protected $inbox;
	protected $inboxStatus;

	public function __construct() {
		$this->addType('sortOrder', Types::INTEGER);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('hourlyRate', Types::INTEGER);
	}

	public const DEFAULT_CURRENCY = 'DKK';

	public function getCurrencyCode(): string {
		return $this->currency ?: self::DEFAULT_CURRENCY;
	}

	/**
	 * The brand's own support inbox as stored (password still encrypted),
	 * or null when the brand has none.
	 *
	 * @return array{enabled: bool, host: string, port: int, security: string, verifyTls: bool, user: string, password: string, folder: string, processedFolder: string}|null
	 */
	public function getInboxConfig(): ?array {
		$stored = json_decode($this->inbox ?? '', true);
		if (!is_array($stored) || ($stored['host'] ?? '') === '') {
			return null;
		}
		return [
			'enabled' => !empty($stored['enabled']),
			'host' => (string)$stored['host'],
			'port' => (int)($stored['port'] ?? 993),
			'security' => (string)($stored['security'] ?? 'ssl'),
			'verifyTls' => (bool)($stored['verifyTls'] ?? true),
			'user' => (string)($stored['user'] ?? ''),
			'password' => (string)($stored['password'] ?? ''),
			'folder' => (string)($stored['folder'] ?? 'INBOX'),
			'processedFolder' => (string)($stored['processedFolder'] ?? ''),
		];
	}

	/**
	 * lastUid / uidValidity: where the inbox was read up to (see MailIntakeService::readInbox).
	 *
	 * @return array{lastRun: ?int, lastSuccess: ?int, lastError: ?string, importedTotal: int, lastUid: ?int, uidValidity: ?int}
	 */
	public function getInboxStatusData(): array {
		$stored = json_decode($this->inboxStatus ?? '', true);
		$stored = is_array($stored) ? $stored : [];
		return [
			'lastRun' => isset($stored['lastRun']) ? (int)$stored['lastRun'] : null,
			'lastSuccess' => isset($stored['lastSuccess']) ? (int)$stored['lastSuccess'] : null,
			'lastError' => isset($stored['lastError']) && $stored['lastError'] !== '' ? (string)$stored['lastError'] : null,
			'importedTotal' => (int)($stored['importedTotal'] ?? 0),
			'lastUid' => isset($stored['lastUid']) ? (int)$stored['lastUid'] : null,
			'uidValidity' => isset($stored['uidValidity']) ? (int)$stored['uidValidity'] : null,
		];
	}

	/** @return string[] */
	public function getGroupIds(): array {
		$groups = json_decode($this->agentGroups ?? '[]', true);
		return is_array($groups) ? array_values(array_filter($groups, 'is_string')) : [];
	}

	/** @param string[] $groups */
	public function setGroupIds(array $groups): void {
		$this->setAgentGroups(json_encode(array_values(array_unique($groups))));
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->name,
			'agentGroups' => $this->getGroupIds(),
			'senderName' => $this->senderName,
			'replyTo' => $this->replyTo,
			'signature' => $this->signature,
			'color' => $this->color,
			'language' => $this->language,
			'sortOrder' => $this->sortOrder,
			'hourlyRate' => $this->hourlyRate,
			'currency' => $this->getCurrencyCode(),
			'inbox' => $this->inboxForForm(),
			'inboxStatus' => $this->getInboxStatusData(),
		];
	}

	/** The inbox settings for the admin form: never the password itself. */
	private function inboxForForm(): ?array {
		$config = $this->getInboxConfig();
		if ($config === null) {
			return null;
		}
		$config['passwordSet'] = $config['password'] !== '';
		unset($config['password']);
		return $config;
	}
}
