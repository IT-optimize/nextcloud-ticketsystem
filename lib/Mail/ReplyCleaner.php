<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Mail;

/**
 * Turns an email body into the text the customer actually wrote: converts
 * HTML to plain text and cuts quoted history, the "reply above this line"
 * marker of Ticket system's own emails, and signatures after "-- ".
 */
class ReplyCleaner {
	/**
	 * Put at the top of every email Ticket system sends, translated; everything
	 * below it in a reply is dropped. Recognised in any language by its
	 * "##- … -##" frame.
	 */
	public const MARKER = '##- Please type your reply above this line -##';

	private const MAX_LENGTH = 50000;
	/** Lines below a sign-off that still count as a signature (name, title, phone, links). */
	private const MAX_SIGNATURE_LINES = 12;

	public function bodyText(ParsedMail $mail): string {
		$text = trim($mail->textBody) !== '' ? $mail->textBody : $this->htmlToText($mail->htmlBody);
		return $this->stripQuoted($text);
	}

	public function stripQuoted(string $text): string {
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = str_replace("\u{00A0}", ' ', $text);
		$text = $this->dropRepeatedLinks($text);
		$text = preg_replace('/^[ \t]+$/m', '', $text) ?? $text;
		$original = trim($text);
		$lines = explode("\n", $text);
		$count = count($lines);
		$cut = $count;

		for ($i = 0; $i < $count; $i++) {
			$line = trim($lines[$i]);
			$next = trim($lines[$i + 1] ?? '');
			$joined = $line . ' ' . $next;

			if (preg_match('/##-\s.{3,80}\s-##/u', $line)) {
				$cut = $i;
				break;
			}
			// "On … wrote:" / "Den … skrev …:" (Gmail, Apple Mail, Thunderbird), also wrapped over two lines.
			if ($this->isAttributionLine($line) || ($next !== '' && $this->isAttributionLine($joined))) {
				$cut = $i;
				break;
			}
			// Outlook: "-----Original Message-----" and translations.
			if (preg_match('/^-{2,}\s*(original message|oprindelig meddelelse|opprinnelig melding|ursprüngliche nachricht|ursprungligt meddelande|message d\'origine|mensaje original|forwarded message|videresendt meddelelse)\s*-{2,}/iu', $line)) {
				$cut = $i;
				break;
			}
			// Outlook header block: "From: …" followed closely by "Sent:" / "Date:".
			if (preg_match('/^\*?(From|Fra|Von|Från|De|Van)\s*:\*?\s*\S/iu', $line)) {
				for ($j = $i + 1; $j <= min($i + 4, $count - 1); $j++) {
					if (preg_match('/^\*?(Sent|Sendt|Gesendet|Skickat|Date|Dato|Datum|Envoyé|Enviado|Verzonden)\s*:/iu', trim($lines[$j]))) {
						$cut = $i;
						break 2;
					}
				}
			}
			// Outlook separator line before the header block.
			if (preg_match('/^_{20,}$/', $line) && preg_match('/^\*?(From|Fra|Von|Från|De|Van)\s*:/iu', $next)) {
				$cut = $i;
				break;
			}
			// Signature delimiter (RFC 3676).
			if ($lines[$i] === '-- ' || $line === '--') {
				$cut = $i;
				break;
			}
		}

		$kept = array_slice($lines, 0, $this->signOffAt(array_slice($lines, 0, $cut)));
		// Drop a trailing block of "> quoted" lines.
		while ($kept !== [] && (trim(end($kept)) === '' || str_starts_with(ltrim(end($kept)), '>'))) {
			array_pop($kept);
		}
		$result = trim(implode("\n", $kept));
		$result = preg_replace("/\n{3,}/", "\n\n", $result) ?? $result;

		if ($result === '') {
			// Everything looked quoted (e.g. a forward without comment): keep it all.
			$result = $original;
		}
		if (mb_strlen($result) > self::MAX_LENGTH) {
			$result = mb_substr($result, 0, self::MAX_LENGTH) . "\n\n[…]";
		}
		return $result;
	}

	/**
	 * Where a signature without the "-- " delimiter starts (Outlook, phones):
	 * the last sign-off line such as "Venlig hilsen" or "Best regards" with
	 * text above it and at most a signature's worth of lines below. Returns
	 * the number of lines to keep.
	 *
	 * @param string[] $lines
	 */
	private function signOffAt(array $lines): int {
		$count = count($lines);
		for ($i = $count - 1; $i > 0; $i--) {
			if (!$this->isSignOff(trim($lines[$i]))) {
				continue;
			}
			$below = count(array_filter(array_slice($lines, $i + 1), fn (string $l) => trim($l) !== ''));
			$above = count(array_filter(array_slice($lines, 0, $i), fn (string $l) => trim($l) !== ''));
			return $below <= self::MAX_SIGNATURE_LINES && $above > 0 ? $i : $count;
		}
		return $count;
	}

	/** "Venlig hilsen", "Mvh.", "Med venlig hilsen / Best regards", "Kind regards," … */
	private function isSignOff(string $line): bool {
		if ($line === '' || mb_strlen($line) > 60) {
			return false;
		}
		foreach (preg_split('#\s*/\s*#u', $line) ?: [] as $part) {
			if (!preg_match('/^(?:(?:med\s+)?(?:venlig(?:st)?|venlige|mange|kærlig|de\s+bedste|bedste)?\s*hilsen(?:er)?|mvh|vh|kh|(?:best|kind|warm|many)?\s*regards|best|cheers|sincerely|yours\s+sincerely|(?:mit\s+)?freundliche[n]?\s+grüßen?)$/iu', rtrim($part, " .,!-"))) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Outlook's plain text writes links as "name <mailto:name>" or
	 * "<http://site/> site". The bracketed copy goes when the line already
	 * shows the same address.
	 */
	private function dropRepeatedLinks(string $text): string {
		return preg_replace_callback('/^.*<(?:mailto:|https?:\/\/)[^<>\s]+>.*$/mi', function (array $m): string {
			return trim(preg_replace_callback('/\s*<(mailto:|https?:\/\/)([^<>\s]+)>\s*/i', function (array $link) use ($m): string {
				$target = rtrim(rawurldecode(explode('?', $link[2])[0]), " /");
				$rest = str_replace($link[0], ' ', $m[0]);
				if ($target !== '' && stripos($rest, $target) !== false) {
					return ' ';
				}
				return ' ' . (strtolower($link[1]) === 'mailto:' ? $target : $link[1] . $link[2]) . ' ';
			}, $m[0]) ?? $m[0]);
		}, $text) ?? $text;
	}

	/**
	 * A quote attribution line: ends with ":", names the verb ("wrote", "skrev", …)
	 * and carries a date, time or address, so "I wrote 3 times:" is not one.
	 */
	private function isAttributionLine(string $line): bool {
		if (mb_strlen($line) > 400 || !str_ends_with(rtrim($line), ':')) {
			return false;
		}
		if (!preg_match('/\b(wrote|skrev|schrieb|a écrit|escribió|ha scritto|schreef|kirjoitti|napisał)\b/iu', $line)) {
			return false;
		}
		return (bool)preg_match('/@|\b(19|20)\d{2}\b|\b\d{1,2}[:.]\d{2}\b/u', $line);
	}

	public function htmlToText(string $html): string {
		if (trim($html) === '') {
			return '';
		}
		// The body is already UTF-8; a leftover <meta charset> would make libxml re-decode it.
		$html = preg_replace('/<meta\b[^>]*charset[^>]*>/i', '', $html) ?? $html;
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$xpath = new \DOMXPath($doc);
		// Quoted history and invisible parts.
		$remove = '//script | //style | //head | //title | //blockquote'
			. ' | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_quote ")]'
			. ' | //*[contains(concat(" ", normalize-space(@class), " "), " gmail_signature ")]'
			. ' | //*[@id="divRplyFwdMsg"] | //*[@id="appendonsend"] | //*[@name="messageReplySection"]'
			. ' | //*[contains(concat(" ", normalize-space(@class), " "), " moz-cite-prefix ")]';
		foreach (iterator_to_array($xpath->query($remove) ?: []) as $node) {
			// Outlook puts the history after #divRplyFwdMsg / #appendonsend as siblings.
			if ($node instanceof \DOMElement && in_array($node->getAttribute('id'), ['divRplyFwdMsg', 'appendonsend'], true)) {
				while ($node->nextSibling !== null) {
					$node->parentNode?->removeChild($node->nextSibling);
				}
			}
			$node->parentNode?->removeChild($node);
		}

		$body = $doc->getElementsByTagName('body')->item(0) ?? $doc->documentElement;
		$text = $body !== null ? $this->nodeText($body) : '';
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
		$text = preg_replace("/\n[ \t]+/", "\n", $text) ?? $text;
		$text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
		$text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
		return trim($text);
	}

	private function nodeText(\DOMNode $node): string {
		if ($node instanceof \DOMText) {
			return preg_replace('/\s+/u', ' ', $node->wholeText) ?? '';
		}
		if (!$node instanceof \DOMElement && !$node instanceof \DOMDocument) {
			return '';
		}
		$tag = $node instanceof \DOMElement ? strtolower($node->tagName) : '';
		if ($tag === 'br') {
			return "\n";
		}
		$out = '';
		foreach ($node->childNodes as $child) {
			$out .= $this->nodeText($child);
		}
		if ($tag === 'a') {
			$href = $node->getAttribute('href');
			if (preg_match('#^https?://#i', $href) && !str_contains($out, $href) && trim($out) !== '') {
				$out .= ' (' . $href . ')';
			}
		}
		if ($tag === 'li') {
			return "\n- " . trim($out);
		}
		if (in_array($tag, ['p', 'div', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'section', 'article', 'header', 'footer', 'pre'], true)) {
			return "\n" . $out . "\n";
		}
		if (in_array($tag, ['td', 'th'], true)) {
			return $out . "\t";
		}
		return $out;
	}
}
