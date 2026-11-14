<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Tests\Unit\Mail;

use OCA\TicketSystem\Mail\ReplyCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReplyCleanerTest extends TestCase {
	private ReplyCleaner $cleaner;

	protected function setUp(): void {
		$this->cleaner = new ReplyCleaner();
	}

	/** @return array<string, array{string, string}> */
	public static function replies(): array {
		return [
			'gmail english' => ["Thanks!\n\nOn Tue, Oct 28, 2026 at 2:05 PM Support <s@x.dk> wrote:\n> old", 'Thanks!'],
			'gmail wrapped over two lines' => ["Thanks!\n\nOn Tue, Oct 28, 2026 at 2:05 PM Ticket system Support <\nsupport@ticketsystem.test> wrote:\n> old", 'Thanks!'],
			'gmail danish' => ["Super\n\ntir. 28. okt. 2026 kl. 14.05 skrev Support <s@x.dk>:\n> gammel", 'Super'],
			'apple mail' => ["Ok\n\n> On 28 Oct 2026, at 14:05, Support <s@x.dk> wrote:\n>\n> old", 'Ok'],
			'outlook danish header block' => ["Svar her\n\nFra: Ticket system <s@x.dk>\nSendt: 28. oktober 2026 14:05\nTil: Kunde\n\ngammelt", 'Svar her'],
			'outlook original message' => ["Tak\n\n-----Oprindelig meddelelse-----\nFra: os", 'Tak'],
			'signature' => ["Fix it please\n-- \nKaren\nCEO", 'Fix it please'],
			'our marker' => ["Ny tekst\n\n" . ReplyCleaner::MARKER . "\nHej igen", 'Ny tekst'],
			'not an attribution' => ["I wrote 3 times:\nfirst on monday", "I wrote 3 times:\nfirst on monday"],
			'only quoted text is kept whole' => ["> just quoted\n> stuff", "> just quoted\n> stuff"],
			'our marker in Danish' => ["Ja tak\n\n##- Skriv dit svar over denne linje -##\nHej igen", 'Ja tak'],
			'outlook signature without delimiter' => [
				"Hej\n\nPrinteren virker ikke.\n\n \n\nVenlig hilsen / Best regards\n\n \n\nKaren Kunde\n\n+45 12 34 56 78\n\nIndkøber\n\n <mailto:karen@kunde.dk%20> karen@kunde.dk \n\n <http://www.kunde.dk/> www.kunde.dk",
				"Hej\n\nPrinteren virker ikke.",
			],
			'phone sign-off' => ["Det virker nu\n\nMvh.\nOle\nSendt fra min iPhone", 'Det virker nu'],
			'only a signature is kept, links tidied' => [
				"Venlig hilsen\n\n \n\nKaren\n\n <mailto:karen@kunde.dk> karen@kunde.dk",
				"Venlig hilsen\n\nKaren\n\nkaren@kunde.dk",
			],
			'sign-off followed by a long text is not a signature' => [
				"Kort intro\nVenlig hilsen\n" . implode("\n", array_map(fn ($i) => "Linje $i", range(1, 13))),
				"Kort intro\nVenlig hilsen\n" . implode("\n", array_map(fn ($i) => "Linje $i", range(1, 13))),
			],
			'a link shown once keeps its address' => ['Se <https://shop.dk/ordre/7> for status', 'Se https://shop.dk/ordre/7 for status'],
			'greeting words inside a sentence stay' => ["Kan I sende en hilsen til Bo?\nTak", "Kan I sende en hilsen til Bo?\nTak"],
		];
	}

	#[DataProvider('replies')]
	public function testStripQuoted(string $input, string $expected): void {
		$this->assertSame($expected, $this->cleaner->stripQuoted($input));
	}

	public function testHtmlToTextDropsGmailQuoteAndKeepsLinks(): void {
		$html = '<div>Se <a href="https://shop.dk/ordre/7">ordren</a><br>Mvh</div><div class="gmail_quote">gammelt</div>';
		$this->assertSame("Se ordren (https://shop.dk/ordre/7)\nMvh", $this->cleaner->htmlToText($html));
	}
}
