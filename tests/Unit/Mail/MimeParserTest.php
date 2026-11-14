<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Tests\Unit\Mail;

use OCA\TicketSystem\Mail\MimeParser;
use OCA\TicketSystem\Mail\ReplyCleaner;
use PHPUnit\Framework\TestCase;

class MimeParserTest extends TestCase {
	private MimeParser $parser;
	private ReplyCleaner $cleaner;

	protected function setUp(): void {
		$this->parser = new MimeParser();
		$this->cleaner = new ReplyCleaner();
	}

	private function fixture(string $name): string {
		return file_get_contents(__DIR__ . '/../../fixtures/' . $name);
	}

	public function testGmailReplyDecodesHeadersAndStripsQuote(): void {
		$mail = $this->parser->parse($this->fixture('gmail-reply.eml'));
		$this->assertSame('karen@kunde.dk', $mail->fromEmail);
		$this->assertSame('Karen Kønig', $mail->fromName);
		$this->assertSame('Re: [#1] Kan ikke logge ind på webshop', $mail->subject);
		$this->assertSame('CAF+gmail123@mail.gmail.com', $mail->messageId);
		$this->assertSame(['unknown-outbound@ticketsystem.test', 'first@kunde.dk'], $mail->references);
		$this->assertFalse($mail->isAutomatic);
		$this->assertSame("Tak, nu virker det! Men jeg kan ikke se min ordre fra i går.\n\nMvh Karen", $this->cleaner->bodyText($mail));
	}

	public function testAddressListsWithCommasInNamesAndEncodedWords(): void {
		$this->assertSame(
			['bo@firma.dk', 'karen@kunde.dk', 'ole@firma.dk', 'x@y.dk'],
			$this->parser->parseAddressList('Bo <bo@firma.dk>, "Kunde, Karen" <Karen@Kunde.dk>; ole@firma.dk (Ole), "a\"b, c" <x@y.dk>, bo@firma.dk, undisclosed-recipients:;'),
		);
		$this->assertSame([], $this->parser->parseAddressList(''));
		$mail = $this->parser->parse("From: Karen <karen@kunde.dk>\r\nTo: support@ticketsystem.test, =?UTF-8?Q?J=C3=B8rgen?= <jorgen@kunde.dk>\r\nCc: \"Bo, IT\" <bo@kunde.dk>\r\nSubject: x\r\n\r\nHej");
		$this->assertSame(['support@ticketsystem.test', 'jorgen@kunde.dk', 'bo@kunde.dk'], $mail->recipients);
	}

	public function testOutlookHtmlInWindows1252WithAttachment(): void {
		$mail = $this->parser->parse($this->fixture('outlook-html.eml'));
		$this->assertSame('jorgen@firma.dk', $mail->fromEmail);
		$this->assertSame('Forkert beløb på faktura', $mail->subject);
		$this->assertCount(1, $mail->attachments);
		$this->assertSame('faktura æøå.pdf', $mail->attachments[0]['filename']);
		$this->assertSame('application/pdf', $mail->attachments[0]['contentType']);
		$this->assertSame($mail->attachments[0]['size'], strlen($mail->attachments[0]['content']));
		$this->assertStringStartsWith('%PDF', $mail->attachments[0]['content']);
		$body = $this->cleaner->bodyText($mail);
		$this->assertStringContainsString('beløbet skal være 1.250 kr.', $body);
		$this->assertStringContainsString('- Faktura 1042', $body);
		$this->assertStringNotContainsString('Gammel tekst', $body);
	}

	public function testUnlabelledLatin1IsConverted(): void {
		$mail = $this->parser->parse($this->fixture('latin1-plain.eml'));
		$this->assertSame('Spørgsmål om levering', $mail->subject);
		$this->assertNull($mail->messageId);
		$this->assertSame('Hvornår kommer min pakke?', $this->cleaner->bodyText($mail));
	}

	public function testAutoReplyAndBounceAreAutomatic(): void {
		$this->assertTrue($this->parser->parse($this->fixture('autoreply.eml'))->isAutomatic);
		$this->assertTrue($this->parser->parse($this->fixture('bounce.eml'))->isAutomatic);
	}

	public function testBounceTellsWhichEmailFailedAndWhy(): void {
		$bounce = $this->parser->parse($this->fixture('bounce.eml'))->bounce;
		$this->assertSame('findes-ikke@kunde.dk', $bounce['recipient']);
		$this->assertSame('failed', $bounce['action']);
		$this->assertSame('5.1.1', $bounce['status']);
		$this->assertSame('550 5.1.1 <findes-ikke@kunde.dk>: Recipient address rejected: User unknown in virtual mailbox table', $bounce['reason']);
		$this->assertSame('ticketsystem.39.f63d32e40d8b.2cbb8203609379560246@ticketsystem.test', $bounce['messageId']);
	}

	public function testDelayedReportAndOrdinaryMailAreNotFailures(): void {
		$delayed = str_replace(['Action: failed', 'Status: 5.1.1'], ['Action: delayed', 'Status: 4.4.1'], $this->fixture('bounce.eml'));
		$this->assertSame('delayed', $this->parser->parse($delayed)->bounce['action']);
		$this->assertNull($this->parser->parse($this->fixture('gmail-reply.eml'))->bounce);
		// A report of another kind (read receipt) is no bounce.
		$receipt = str_replace('report-type=delivery-status', 'report-type=disposition-notification', $this->fixture('bounce.eml'));
		$this->assertNull($this->parser->parse($receipt)->bounce);
	}

	public function testNewsletterHeadersAreAutomatic(): void {
		$mail = $this->parser->parse("From: News <news@shop.dk>\r\nList-Unsubscribe: <mailto:x@shop.dk>\r\nSubject: Tilbud\r\n\r\nKøb nu");
		$this->assertTrue($mail->isAutomatic);
	}

	public function testAddressParsing(): void {
		$this->assertSame(['a@b.dk', 'Ann B'], $this->parser->parseAddress('"Ann B" <A@B.dk>'));
		$this->assertSame(['a@b.dk', null], $this->parser->parseAddress('a@b.dk'));
		$this->assertSame([null, null], $this->parser->parseAddress('not an address'));
	}

	public function testRfc2231FilenameAndEncodedWords(): void {
		$raw = "From: x@y.dk\r\nSubject: =?UTF-8?B?w6bDuMOl?= =?UTF-8?Q?_test?=\r\nContent-Type: multipart/mixed; boundary=b\r\n\r\n--b\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHej\r\n--b\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename*0*=UTF-8''r%C3%A6k; filename*1=ning.txt\r\nContent-Transfer-Encoding: base64\r\n\r\naGVq\r\n--b--\r\n";
		$mail = $this->parser->parse($raw);
		$this->assertSame('æøå test', $mail->subject);
		$this->assertSame('rækning.txt', $mail->attachments[0]['filename']);
		$this->assertSame(3, $mail->attachments[0]['size']);
	}
}
