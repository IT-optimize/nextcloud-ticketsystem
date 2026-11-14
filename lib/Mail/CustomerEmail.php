<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Mail;

use OCP\Mail\IEMailTemplate;

/**
 * The look of every email to a customer: a white card on a light background,
 * the brand's name and colour instead of the Nextcloud logo, left-aligned
 * text that reads like a letter. Built from tables and inline styles, as
 * mail programs (Outlook above all) need.
 *
 * Texts given to the add… methods are HTML, as IEMailTemplate defines;
 * callers escape them. The plain-text version is built alongside.
 */
class CustomerEmail implements IEMailTemplate {
	private const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
	private const DEFAULT_COLOR = '#0082c9';

	private string $subject = '';
	private string $preheader = '';
	private string $marker = '';
	private string $footer = '';
	/** @var list<string> */
	private array $html = [];
	/** @var list<string> */
	private array $text = [];
	private string $color;

	public function __construct(
		private string $brandName,
		?string $brandColor,
		private string $language = 'da',
	) {
		$this->color = preg_match('/^#[0-9a-f]{6}$/i', (string)$brandColor) ? strtolower((string)$brandColor) : self::DEFAULT_COLOR;
	}

	/** Shown in the inbox list next to the subject. */
	public function setPreheader(string $text): void {
		$this->preheader = $text;
	}

	/** The "reply above this line" marker; replies are cut where it appears. */
	public function setMarker(string $text): void {
		$this->marker = $text;
	}

	public function setSubject(string $subject): void {
		$this->subject = $subject;
	}

	/** The brand name always heads the card; there is no separate header. */
	public function addHeader(): void {
	}

	public function addHeading(string $title, $plainTitle = ''): void {
		$this->html[] = '<h1 style="margin:20px 0 0;font-family:' . self::FONT . ';font-size:22px;line-height:1.3;font-weight:600;color:#111827;">' . $title . '</h1>';
		$this->text[] = $plainTitle !== '' && $plainTitle !== false ? (string)$plainTitle : strip_tags($title);
	}

	public function addBodyText(string $text, $plainText = ''): void {
		$this->html[] = '<p style="margin:16px 0 0;font-family:' . self::FONT . ';font-size:16px;line-height:1.6;color:#1f2937;">' . $text . '</p>';
		$this->text[] = $plainText !== '' && $plainText !== false ? (string)$plainText : html_entity_decode(strip_tags($text));
	}

	public function addBodyListItem(string $text, string $metaInfo = '', string $icon = '', $plainText = '', $plainMetaInfo = '', $plainIndent = 0): void {
		$this->addBodyText($text . ($metaInfo !== '' ? ' <span style="color:#6b7280;">' . $metaInfo . '</span>' : ''),
			trim(($plainText ?: strip_tags($text)) . ' ' . ($plainMetaInfo ?: strip_tags($metaInfo))));
	}

	public function addBodyButton(string $text, string $url, $plainText = ''): void {
		$this->html[] = $this->buttons([[$text, $url, true]]);
		$this->text[] = ($plainText ?: strip_tags($text)) . ': ' . $url;
	}

	/** A quieter, outlined button, e.g. the portal link under a personal reply. */
	public function addSecondaryButton(string $text, string $url, string $plainText = ''): void {
		$this->html[] = $this->buttons([[$text, $url, false]]);
		$this->text[] = ($plainText ?: strip_tags($text)) . ': ' . $url;
	}

	public function addBodyButtonGroup(string $textLeft, string $urlLeft, string $textRight, string $urlRight, string $plainTextLeft = '', string $plainTextRight = ''): void {
		$this->html[] = $this->buttons([[$textLeft, $urlLeft, true], [$textRight, $urlRight, false]]);
		$this->text[] = ($plainTextLeft ?: strip_tags($textLeft)) . ': ' . $urlLeft . "\n" . ($plainTextRight ?: strip_tags($textRight)) . ': ' . $urlRight;
	}

	public function addFooter(string $text = '', ?string $lang = null): void {
		$this->footer = $text;
	}

	public function renderSubject(): string {
		return $this->subject;
	}

	public function renderHtml(): string {
		$font = self::FONT;
		$e = fn (string $s) => htmlspecialchars($s, ENT_QUOTES);
		// Characters that keep mail programs from filling the preview with the text that follows.
		$padding = str_repeat('&#847;&zwnj;&nbsp;', 40);
		$body = implode("\n", $this->html);
		$marker = $this->marker !== ''
			? '<tr><td style="padding:0 6px 10px;font-family:' . $font . ';font-size:11px;line-height:1.4;color:#9ca3af;">' . $e($this->marker) . '</td></tr>'
			: '';
		$footer = $this->footer !== ''
			? '<tr><td align="center" style="padding:16px 6px 0;font-family:' . $font . ';font-size:12px;line-height:1.5;color:#6b7280;">' . $this->footer . '</td></tr>'
			: '';
		return '<!DOCTYPE html>
<html lang="' . $e($this->language) . '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>' . $e($this->subject) . '</title>
<style>
@media (max-width: 480px) {
	.hd-card { padding: 22px 20px 24px !important; }
	.hd-outer { padding: 16px 8px 24px !important; }
}
</style>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;max-width:0;overflow:hidden;opacity:0;color:transparent;font-size:1px;line-height:1px;mso-hide:all;">' . $e($this->preheader) . $padding . '</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f3f4f6;">
<tr><td align="center" class="hd-outer" style="padding:28px 12px 32px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
' . $marker . '
<tr><td class="hd-card" style="background-color:#ffffff;border-radius:10px;border-top:4px solid ' . $this->color . ';padding:28px 32px 32px;">
<div style="font-family:' . $font . ';font-size:13px;line-height:1.4;font-weight:700;letter-spacing:0.02em;color:' . $this->textColor() . ';">' . $e($this->brandName) . '</div>
' . $body . '
</td></tr>
' . $footer . '
</table>
</td></tr>
</table>
</body>
</html>';
	}

	public function renderText(): string {
		$parts = $this->marker !== '' ? [$this->marker] : [];
		array_push($parts, ...$this->text);
		if ($this->footer !== '') {
			$parts[] = "--\n" . html_entity_decode(strip_tags($this->footer));
		}
		return implode("\n\n", $parts) . "\n";
	}

	/**
	 * Bulletproof buttons (a coloured cell around the link, so Outlook shows
	 * them too). The first is filled with the brand colour, others outlined.
	 *
	 * @param list<array{0: string, 1: string, 2: bool}> $buttons text (HTML), URL, filled
	 */
	private function buttons(array $buttons): string {
		$cells = [];
		foreach ($buttons as [$text, $url, $filled]) {
			$background = $filled ? $this->color : '#ffffff';
			$foreground = $filled ? $this->onColor() : '#111827';
			$border = $filled ? $this->color : '#d1d5db';
			$cells[] = '<td style="border-radius:6px;background-color:' . $background . ';border:1px solid ' . $border . ';">'
				. '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;padding:11px 20px;font-family:' . self::FONT . ';font-size:15px;line-height:1.2;font-weight:600;color:' . $foreground . ';text-decoration:none;border-radius:6px;">' . $text . '</a></td>';
		}
		return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;"><tr>'
			. implode('<td style="width:10px;font-size:0;">&nbsp;</td>', $cells) . '</tr></table>';
	}

	/** White text on dark brand colours, near-black on light ones. */
	private function onColor(): string {
		return $this->luminance() > 0.5 ? '#111827' : '#ffffff';
	}

	/** The brand name in the brand colour, unless it is too light to read on white. */
	private function textColor(): string {
		return $this->luminance() > 0.6 ? '#374151' : $this->color;
	}

	private function luminance(): float {
		[$r, $g, $b] = array_map(fn (string $hex) => hexdec($hex) / 255, str_split(substr($this->color, 1), 2));
		return 0.299 * $r + 0.587 * $g + 0.114 * $b;
	}
}
