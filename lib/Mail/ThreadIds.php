<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Mail;

/**
 * Message-IDs for mail Ticket system sends, so customer replies thread in their
 * mail program and find their way back to the ticket.
 *
 * An id names its ticket and is signed with the server secret:
 * "ticketsystem.42.k3j9x0a1b2c4.<signature>@example.com". A reply that references
 * it joins ticket 42 even without "[#42]" in the subject, and nobody can make
 * up an id for someone else's ticket. Only lowercase letters and digits, as
 * some mail systems change the case of headers.
 */
class ThreadIds {
	private const PREFIX = 'ticketsystem';
	/** Stands for the whole ticket in References, so every email of it shares one thread. */
	private const ROOT = 'ticket';

	public function __construct(
		private string $secret,
	) {
	}

	/** A new id for one outgoing email. */
	public function newId(int $ticketId, string $domain): string {
		return $this->build($ticketId, bin2hex(random_bytes(6)), $domain);
	}

	/** The same id for every email of a ticket, never sent as a Message-ID itself. */
	public function rootId(int $ticketId, string $domain): string {
		return $this->build($ticketId, self::ROOT, $domain);
	}

	/**
	 * The ticket named by the first genuine Ticket system id among these
	 * (from In-Reply-To / References, without <>), or null.
	 *
	 * @param string[] $ids
	 */
	public function ticketIdFrom(array $ids): ?int {
		foreach ($ids as $id) {
			if (!preg_match('/^' . self::PREFIX . '\.(\d{1,18})\.([a-z0-9]{1,32})\.([0-9a-f]{20})@/', strtolower(trim($id, " \t<>")), $m)) {
				continue;
			}
			if (hash_equals($this->sign((int)$m[1], $m[2]), $m[3])) {
				return (int)$m[1];
			}
		}
		return null;
	}

	/** The domain part of an address, for ids that look like they came from it. */
	public static function domainOf(string $address): string {
		$domain = strtolower(substr(strrchr($address, '@') ?: '', 1));
		return preg_match('/^[a-z0-9.-]+$/', $domain) ? $domain : 'localhost';
	}

	private function build(int $ticketId, string $part, string $domain): string {
		return sprintf('%s.%d.%s.%s@%s', self::PREFIX, $ticketId, $part, $this->sign($ticketId, $part), $domain);
	}

	private function sign(int $ticketId, string $part): string {
		return substr(hash_hmac('sha256', self::PREFIX . '|' . $ticketId . '|' . $part, $this->secret), 0, 20);
	}
}
