<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Mail;

/** The parts of an incoming email that Ticket system uses. */
class ParsedMail {
	/** @var string[] Message-IDs from In-Reply-To and References, without <> */
	public array $references = [];
	public ?string $messageId = null;
	public string $subject = '';
	public ?string $fromEmail = null;
	public ?string $fromName = null;
	public ?string $replyToEmail = null;
	public ?string $replyToName = null;
	/** @var string[] To and Cc addresses, lower-cased */
	public array $recipients = [];
	public string $textBody = '';
	public string $htmlBody = '';
	/** Auto-reply, bounce or bulk mail: never creates or reopens tickets. */
	public bool $isAutomatic = false;
	/**
	 * A delivery report (bounce): who could not be reached, whether it failed
	 * for good ("failed") or is still being tried ("delayed"), the server's
	 * reason, and the Message-ID of the email that bounced.
	 *
	 * @var array{recipient: ?string, action: ?string, status: ?string, reason: ?string, messageId: ?string}|null
	 */
	public ?array $bounce = null;
	/** @var list<array{filename: string, contentType: string, size: int, content: string, inline: bool}> */
	public array $attachments = [];
	/** @var array<string, string> lower-cased header name => decoded value (first occurrence) */
	public array $headers = [];

	/** Last line of every request the Helpdesk app sends. */
	public const HELPDESK_MARKER = 'Sent with Helpdesk for Nextcloud';

	/**
	 * Sent by the Helpdesk app on behalf of the person in Reply-To: it carries the
	 * X-Nextcloud-Helpdesk header, or (when the sending Nextcloud could not set
	 * headers) ends with HELPDESK_MARKER.
	 */
	public function isHelpdeskRequest(): bool {
		return isset($this->headers['x-nextcloud-helpdesk'])
			|| preg_match('/^' . preg_quote(self::HELPDESK_MARKER, '/') . '\s*$/m', $this->textBody) === 1;
	}
}
