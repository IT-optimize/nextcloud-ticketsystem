<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Service;

use OCA\TicketSystem\Db\Company;
use OCA\TicketSystem\Db\CompanyMapper;
use OCA\TicketSystem\Exception\NotFoundException;
use OCA\TicketSystem\Exception\ValidationException;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Customer companies. A ticket belongs to the company whose email domain its
 * customer writes from, unless an agent picks another.
 */
class CompanyService {
	/** Domains of free mail providers: never enough to tell which company someone works for. */
	private const PUBLIC_DOMAINS = ['gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.dk', 'outlook.com', 'outlook.dk', 'live.com', 'live.dk',
		'yahoo.com', 'yahoo.dk', 'icloud.com', 'me.com', 'mail.dk', 'jubii.dk', 'webspeed.dk', 'gmx.com', 'gmx.de', 'proton.me', 'protonmail.com', 'msn.com', 'aol.com'];

	/** @var Company[]|null */
	private ?array $cache = null;

	public function __construct(
		private CompanyMapper $mapper,
		private ITimeFactory $time,
	) {
	}

	/** @return Company[] */
	public function all(): array {
		return $this->cache ??= $this->mapper->findAll();
	}

	/** @throws NotFoundException */
	public function get(int $id): Company {
		foreach ($this->all() as $company) {
			if ($company->getId() === $id) {
				return $company;
			}
		}
		throw new NotFoundException('Company not found');
	}

	public function find(?int $id): ?Company {
		if ($id === null) {
			return null;
		}
		try {
			return $this->get($id);
		} catch (NotFoundException) {
			return null;
		}
	}

	/** The company a customer address belongs to, by its domain. */
	public function matchEmail(?string $email): ?Company {
		if ($email === null || !str_contains($email, '@')) {
			return null;
		}
		$domain = strtolower(substr($email, strrpos($email, '@') + 1));
		foreach ($this->all() as $company) {
			if (in_array($domain, $company->getDomainList(), true)) {
				return $company;
			}
		}
		return null;
	}

	/** @return array<int, int> */
	public function ticketCounts(): array {
		return $this->mapper->ticketCounts();
	}

	/** @throws ValidationException */
	public function create(array $data): Company {
		$company = new Company();
		$now = $this->time->getTime();
		$company->setCreatedAt($now);
		$company->setUpdatedAt($now);
		$this->apply($company, $data + ['domains' => []], true);
		$company = $this->mapper->insert($company);
		$this->cache = null;
		$this->mapper->claimTickets($company->getId(), $company->getDomainList());
		return $company;
	}

	/** @throws NotFoundException|ValidationException */
	public function update(int $id, array $data, bool $canSetRate): Company {
		$company = $this->get($id);
		if (!$canSetRate) {
			unset($data['hourlyRate']);
		}
		$this->apply($company, $data, false);
		$company->setUpdatedAt($this->time->getTime());
		$company = $this->mapper->update($company);
		$this->cache = null;
		$this->mapper->claimTickets($company->getId(), $company->getDomainList());
		return $company;
	}

	/** @throws NotFoundException */
	public function delete(int $id): void {
		$company = $this->get($id);
		$this->mapper->releaseTickets($id);
		$this->mapper->delete($company);
		$this->cache = null;
	}

	private function apply(Company $company, array $data, bool $isNew): void {
		if ($isNew || array_key_exists('name', $data)) {
			$name = trim(preg_replace('/\s+/u', ' ', (string)($data['name'] ?? '')) ?? '');
			if ($name === '') {
				throw new ValidationException('A company needs a name');
			}
			$company->setName(mb_substr($name, 0, 255));
		}
		if (array_key_exists('domains', $data)) {
			$domains = [];
			foreach ((array)$data['domains'] as $domain) {
				$domain = strtolower(trim((string)$domain));
				$domain = ltrim($domain, '@');
				if ($domain === '') {
					continue;
				}
				if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
					throw new ValidationException(sprintf('“%s” is not an email domain (like kunde.dk)', $domain));
				}
				if (in_array($domain, self::PUBLIC_DOMAINS, true)) {
					throw new ValidationException(sprintf('%s is a public mail service, so it cannot identify a company', $domain));
				}
				foreach ($this->all() as $other) {
					if ($other->getId() !== $company->getId() && in_array($domain, $other->getDomainList(), true)) {
						throw new ValidationException(sprintf('%s already belongs to %s', $domain, $other->getName()));
					}
				}
				$domains[] = $domain;
			}
			$company->setDomainList($domains);
		}
		if (array_key_exists('hourlyRate', $data)) {
			$company->setHourlyRate(TeamService::cleanRate($data['hourlyRate']));
		}
		if (array_key_exists('notes', $data)) {
			$notes = trim((string)($data['notes'] ?? ''));
			$company->setNotes($notes !== '' ? mb_substr($notes, 0, 5000) : null);
		}
	}
}
