<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Mail;

/**
 * Parses a raw RFC 5322 / MIME email into a ParsedMail.
 *
 * Handles nested multiparts, base64 and quoted-printable, encoded-word headers
 * (=?UTF-8?B?…?=), RFC 2231 file names, and any charset PHP's mbstring/iconv
 * knows, converting everything to UTF-8. Attachments keep their decoded content.
 */
class MimeParser {
	private const MAX_DEPTH = 10;

	public function parse(string $raw): ParsedMail {
		$mail = new ParsedMail();
		[$headers, $body] = $this->splitHeadersBody($raw);
		$mail->headers = $this->decodedHeaderMap($headers);

		$mail->subject = $this->cleanLine($mail->headers['subject'] ?? '');
		[$mail->fromEmail, $mail->fromName] = $this->parseAddress($mail->headers['from'] ?? '');
		[$mail->replyToEmail, $mail->replyToName] = $this->parseAddress($mail->headers['reply-to'] ?? '');
		$mail->recipients = array_values(array_unique(array_merge(
			$this->parseAddressList($mail->headers['to'] ?? ''),
			$this->parseAddressList($mail->headers['cc'] ?? ''),
		)));
		$mail->messageId = $this->firstMessageId($mail->headers['message-id'] ?? '');
		$mail->references = array_values(array_unique(array_merge(
			$this->allMessageIds($mail->headers['in-reply-to'] ?? ''),
			$this->allMessageIds($mail->headers['references'] ?? ''),
		)));
		$this->walkPart($headers, $body, $mail, 0);
		// After the body: a Helpdesk request is recognised by its last line too.
		$mail->isAutomatic = $mail->isAutomatic || $this->isAutomatic($mail);
		return $mail;
	}

	/** @param list<array{0: string, 1: string}> $headers raw (name, value) pairs */
	private function walkPart(array $headers, string $body, ParsedMail $mail, int $depth): void {
		$contentType = $this->headerValue($headers, 'content-type') ?? 'text/plain; charset=us-ascii';
		[$type, $params] = $this->parseHeaderParams($contentType);
		$type = strtolower($type ?: 'text/plain');
		$disposition = $this->headerValue($headers, 'content-disposition');
		[$dispositionType, $dispositionParams] = $disposition !== null ? $this->parseHeaderParams($disposition) : ['', []];
		$filename = $dispositionParams['filename'] ?? $params['name'] ?? null;

		if (str_starts_with($type, 'multipart/') && isset($params['boundary']) && $depth < self::MAX_DEPTH) {
			if ($type === 'multipart/report') {
				$mail->isAutomatic = true; // delivery status notification (bounce)
				if (strtolower($params['report-type'] ?? '') === 'delivery-status') {
					$mail->bounce ??= ['recipient' => null, 'action' => null, 'status' => null, 'reason' => null, 'messageId' => null];
				}
			}
			foreach ($this->splitMultipart($body, $params['boundary']) as $part) {
				[$partHeaders, $partBody] = $this->splitHeadersBody($part);
				$this->walkPart($partHeaders, $partBody, $mail, $depth + 1);
			}
			return;
		}

		$decoded = $this->decodeTransfer($body, $this->headerValue($headers, 'content-transfer-encoding') ?? '');

		if ($mail->bounce !== null) {
			if ($type === 'message/delivery-status') {
				$this->readDeliveryStatus($decoded, $mail);
				return;
			}
			if (($type === 'message/rfc822' || $type === 'text/rfc822-headers') && $mail->bounce['messageId'] === null) {
				[$original] = $this->splitHeadersBody($decoded);
				$mail->bounce['messageId'] = $this->firstMessageId($this->headerValue($original, 'message-id') ?? '');
			}
		}
		$isAttachment = strtolower($dispositionType) === 'attachment'
			|| ($filename !== null && !in_array($type, ['text/plain', 'text/html'], true))
			|| !in_array($type, ['text/plain', 'text/html'], true);

		if ($isAttachment) {
			// Inline images without a name (signature logos) are not worth listing.
			if ($filename !== null || str_starts_with($type, 'application/') || $type === 'message/rfc822') {
				$mail->attachments[] = [
					'filename' => $filename !== null ? $this->cleanLine($filename) : ($type === 'message/rfc822' ? 'forwarded-message.eml' : 'attachment'),
					'contentType' => $type,
					'size' => strlen($decoded),
					'content' => $decoded,
					// Shown inside the text (a logo in the signature, a pasted screenshot).
					'inline' => $this->headerValue($headers, 'content-id') !== null || strtolower($dispositionType) === 'inline',
				];
			}
			return;
		}

		$text = $this->toUtf8($decoded, $params['charset'] ?? null);
		// First body part of each kind wins (later ones are usually forwarded content).
		if ($type === 'text/html') {
			if ($mail->htmlBody === '') {
				$mail->htmlBody = $text;
			}
		} elseif ($mail->textBody === '') {
			$mail->textBody = $text;
		}
	}

	/**
	 * The fields of a delivery report (RFC 3464): blocks of "Name: value"
	 * lines, the first about the message, then one per recipient. The first
	 * recipient that failed wins.
	 */
	private function readDeliveryStatus(string $report, ParsedMail $mail): void {
		$report = str_replace("\r\n", "\n", $report);
		foreach (preg_split("/\n\s*\n/", $report) ?: [] as $block) {
			[$fields] = $this->splitHeadersBody(trim($block) . "\n\n");
			$recipient = $this->headerValue($fields, 'final-recipient') ?? $this->headerValue($fields, 'original-recipient');
			if ($recipient === null || ($mail->bounce['recipient'] !== null && $mail->bounce['action'] === 'failed')) {
				continue;
			}
			// "rfc822; someone@example.com"
			$address = strtolower(trim((string)preg_replace('/^[^;]*;\s*/', '', $recipient), " <>"));
			$mail->bounce['recipient'] = filter_var($address, FILTER_VALIDATE_EMAIL) !== false ? $address : null;
			$mail->bounce['action'] = strtolower(trim($this->headerValue($fields, 'action') ?? '')) ?: null;
			$mail->bounce['status'] = trim($this->headerValue($fields, 'status') ?? '') ?: null;
			$reason = $this->headerValue($fields, 'diagnostic-code');
			$mail->bounce['reason'] = $reason !== null ? mb_substr(trim((string)preg_replace('/^[^;]*;\s*/', '', $reason)), 0, 300) : null;
		}
	}

	/** @return array{0: list<array{0: string, 1: string}>, 1: string} */
	private function splitHeadersBody(string $raw): array {
		$raw = str_replace("\r\n", "\n", $raw);
		$pos = strpos($raw, "\n\n");
		if ($pos === false) {
			$headerBlock = $raw;
			$body = '';
		} else {
			$headerBlock = substr($raw, 0, $pos);
			$body = substr($raw, $pos + 2);
		}
		$headers = [];
		foreach (explode("\n", $headerBlock) as $line) {
			if ($line === '') {
				continue;
			}
			if (($line[0] === ' ' || $line[0] === "\t") && $headers !== []) {
				$headers[count($headers) - 1][1] .= ' ' . ltrim($line);
				continue;
			}
			$colon = strpos($line, ':');
			if ($colon === false) {
				continue;
			}
			$headers[] = [strtolower(trim(substr($line, 0, $colon))), trim(substr($line, $colon + 1))];
		}
		return [$headers, $body];
	}

	/** @return array<string, string> */
	private function decodedHeaderMap(array $headers): array {
		$map = [];
		foreach ($headers as [$name, $value]) {
			if (!isset($map[$name])) {
				$map[$name] = $this->decodeHeader($value);
			}
		}
		return $map;
	}

	private function headerValue(array $headers, string $name): ?string {
		foreach ($headers as [$n, $value]) {
			if ($n === $name) {
				return $value;
			}
		}
		return null;
	}

	/** Decodes =?charset?B|Q?…?= encoded words to UTF-8. */
	public function decodeHeader(string $value): string {
		if (!str_contains($value, '=?')) {
			return $this->toUtf8($value, null);
		}
		// Whitespace between adjacent encoded words is not significant (RFC 2047).
		$value = preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value) ?? $value;
		$decoded = preg_replace_callback(
			'/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/',
			function (array $m): string {
				$charset = preg_replace('/\*.*$/', '', $m[1]); // drop RFC 2231 language
				$data = strtoupper($m[2]) === 'B'
					? (base64_decode($m[3], false) ?: '')
					: quoted_printable_decode(str_replace('_', ' ', $m[3]));
				return $this->toUtf8($data, $charset);
			},
			$value,
		);
		return $decoded ?? $value;
	}

	/**
	 * "text/plain; charset=utf-8; name*=UTF-8''r%C3%A6kning.pdf" → ['text/plain', ['charset' => …, 'name' => …]]
	 * @return array{0: string, 1: array<string, string>}
	 */
	private function parseHeaderParams(string $value): array {
		$parts = $this->splitParams($value);
		$main = trim(array_shift($parts) ?? '');
		$params = [];
		$continued = [];
		foreach ($parts as $part) {
			$eq = strpos($part, '=');
			if ($eq === false) {
				continue;
			}
			$key = strtolower(trim(substr($part, 0, $eq)));
			$val = trim(substr($part, $eq + 1));
			if (str_starts_with($val, '"') && str_ends_with($val, '"') && strlen($val) >= 2) {
				$val = stripcslashes(substr($val, 1, -1));
			}
			// RFC 2231: name*0*=…; name*1*=… and name*=charset''percent-encoded
			if (preg_match('/^([^*]+)\*(\d+)?(\*)?$/', $key, $m)) {
				$continued[$m[1]][(int)($m[2] ?? 0)] = [$val, isset($m[3]) || ($m[2] ?? '') === ''];
				continue;
			}
			$params[$key] = $this->decodeHeader($val);
		}
		foreach ($continued as $key => $pieces) {
			ksort($pieces);
			$charset = null;
			$joined = '';
			foreach ($pieces as $index => [$val, $encoded]) {
				if ($encoded) {
					if ($index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/", $val, $m)) {
						$charset = $m[1] ?: null;
						$val = $m[2];
					}
					$val = rawurldecode($val);
				}
				$joined .= $val;
			}
			$params[$key] = $this->toUtf8($joined, $charset);
		}
		return [$main, $params];
	}

	/** Splits on ";" outside quotes. */
	private function splitParams(string $value): array {
		$parts = [];
		$current = '';
		$quoted = false;
		$len = strlen($value);
		for ($i = 0; $i < $len; $i++) {
			$c = $value[$i];
			if ($c === '\\' && $quoted && $i + 1 < $len) {
				$current .= $c . $value[++$i];
				continue;
			}
			if ($c === '"') {
				$quoted = !$quoted;
			}
			if ($c === ';' && !$quoted) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $c;
		}
		$parts[] = $current;
		return $parts;
	}

	/** @return string[] */
	private function splitMultipart(string $body, string $boundary): array {
		$parts = [];
		$delimiter = '--' . $boundary;
		$lines = explode("\n", $body);
		$current = null;
		foreach ($lines as $line) {
			$trimmed = rtrim($line, "\r \t");
			if ($trimmed === $delimiter . '--') {
				break;
			}
			if ($trimmed === $delimiter) {
				if ($current !== null) {
					$parts[] = implode("\n", $current);
				}
				$current = [];
				continue;
			}
			if ($current !== null) {
				$current[] = $line;
			}
		}
		if ($current !== null && $current !== []) {
			$parts[] = implode("\n", $current);
		}
		return $parts;
	}

	private function decodeTransfer(string $body, string $encoding): string {
		return match (strtolower(trim($encoding))) {
			'base64' => base64_decode(preg_replace('/[^A-Za-z0-9+\/=]/', '', $body) ?? '', false) ?: '',
			'quoted-printable' => quoted_printable_decode($body),
			default => $body,
		};
	}

	public function toUtf8(string $data, ?string $charset): string {
		$charset = $charset !== null ? strtolower(trim($charset, " \"'")) : null;
		if ($charset === null || $charset === '' || in_array($charset, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
			if (mb_check_encoding($data, 'UTF-8')) {
				return $data;
			}
			$charset = 'windows-1252'; // the usual culprit for unlabelled 8-bit mail
		}
		$aliases = ['iso-8859-1' => 'windows-1252', 'latin1' => 'windows-1252', 'ks_c_5601-1987' => 'cp949', 'gb2312' => 'gbk'];
		$charset = $aliases[$charset] ?? $charset;
		try {
			$converted = @mb_convert_encoding($data, 'UTF-8', $charset);
			if (is_string($converted)) {
				return $converted;
			}
		} catch (\ValueError) {
			// unknown to mbstring; try iconv
		}
		$converted = @iconv($charset, 'UTF-8//IGNORE', $data);
		if (is_string($converted)) {
			return $converted;
		}
		return mb_convert_encoding($data, 'UTF-8', 'windows-1252');
	}

	/**
	 * '"Karen Kunde" <Karen@Example.dk>' → ['karen@example.dk', 'Karen Kunde']
	 * @return array{0: ?string, 1: ?string}
	 */
	public function parseAddress(string $value): array {
		$value = trim($value);
		if ($value === '') {
			return [null, null];
		}
		if (preg_match('/^(.*)<([^<>\s]+@[^<>\s]+)>/', $value, $m)) {
			$name = trim($m[1], " \t\"'");
			$email = $m[2];
		} elseif (preg_match('/([^\s<>",;]+@[^\s<>",;]+)/', $value, $m)) {
			$email = $m[1];
			$name = null;
			if (preg_match('/\(([^)]+)\)/', $value, $c)) {
				$name = trim($c[1]);
			}
		} else {
			return [null, null];
		}
		$email = strtolower(trim($email, '.'));
		if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
			return [null, null];
		}
		return [$email, $name !== null && $name !== '' ? Names::clean($this->cleanLine($name)) : null];
	}

	/**
	 * 'Bo <bo@x.dk>, "Kunde, Karen" <Karen@x.dk>' → ['bo@x.dk', 'karen@x.dk']
	 * @return string[]
	 */
	public function parseAddressList(string $value): array {
		$parts = [];
		$current = '';
		$quoted = false;
		$angle = false;
		$length = strlen($value);
		for ($i = 0; $i < $length; $i++) {
			$char = $value[$i];
			if ($char === '\\' && $quoted && $i + 1 < $length) {
				$current .= $char . $value[++$i];
				continue;
			}
			if ($char === '"' && !$angle) {
				$quoted = !$quoted;
			} elseif ($char === '<' && !$quoted) {
				$angle = true;
			} elseif ($char === '>' && !$quoted) {
				$angle = false;
			} elseif (($char === ',' || $char === ';') && !$quoted && !$angle) {
				$parts[] = $current;
				$current = '';
				continue;
			}
			$current .= $char;
		}
		$parts[] = $current;
		$emails = [];
		foreach ($parts as $part) {
			[$email] = $this->parseAddress($part);
			if ($email !== null) {
				$emails[$email] = true;
			}
		}
		return array_keys($emails);
	}

	private function firstMessageId(string $value): ?string {
		return $this->allMessageIds($value)[0] ?? (trim($value) !== '' ? trim($value, " <>") : null);
	}

	/** @return string[] */
	private function allMessageIds(string $value): array {
		preg_match_all('/<([^<>\s]+)>/', $value, $m);
		return $m[1];
	}

	private function isAutomatic(ParsedMail $mail): bool {
		$h = $mail->headers;
		$autoSubmitted = strtolower(trim($h['auto-submitted'] ?? 'no'));
		if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
			return true;
		}
		if (in_array(strtolower(trim($h['precedence'] ?? '')), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
			return true;
		}
		foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress', 'list-id', 'list-unsubscribe', 'feedback-id'] as $name) {
			if (isset($h[$name])) {
				// Outlook sets X-Auto-Response-Suppress on its own auto-replies; mailing
				// lists and newsletters carry List-* headers.
				return true;
			}
		}
		$from = strtolower($mail->fromEmail ?? '');
		// Requests from the Helpdesk app come from a Nextcloud's no-reply address on purpose.
		if (!$mail->isHelpdeskRequest() && preg_match('/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/', $from)) {
			return true;
		}
		return (bool)preg_match('/^(auto(matic)? ?(reply|response|svar)|automatisk svar|out of (the )?office|fraværende|abwesenheit|autosvar)\b/iu', $mail->subject);
	}

	private function cleanLine(string $value): string {
		return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
	}
}
