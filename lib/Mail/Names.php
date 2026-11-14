<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Mail;

/** People's names as mail programs send them, made fit for a ticket and a greeting. */
class Names {
	/**
	 * "Anna Jensen | Firma A/S" → "Anna Jensen": display names
	 * often carry a company or title after a separator.
	 */
	public static function clean(?string $name): ?string {
		if ($name === null) {
			return null;
		}
		$name = trim($name, " \t\"'");
		$parts = preg_split('/\s+[|–—\/]\s+|\s+-\s+|\s+\(/u', $name) ?: [$name];
		$name = trim($parts[0], " \t\"',");
		return $name !== '' ? $name : null;
	}

	/**
	 * The name to greet with: "Anna Jensen" → "Anna",
	 * "Jensen, Anna" → "Anna". Null when there is no usable name.
	 */
	public static function first(?string $name): ?string {
		$name = self::clean($name);
		if ($name === null || str_contains($name, '@')) {
			return null;
		}
		if (str_contains($name, ',')) {
			$name = trim(substr($name, strpos($name, ',') + 1));
		}
		$first = preg_split('/\s+/u', $name)[0] ?? '';
		return mb_strlen($first) >= 2 ? $first : null;
	}
}
