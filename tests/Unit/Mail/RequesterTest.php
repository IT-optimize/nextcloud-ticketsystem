<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Tests\Unit\Mail;

use OCA\TicketSystem\Mail\MimeParser;
use OCA\TicketSystem\Service\MailIntakeService;
use PHPUnit\Framework\TestCase;

/** Who a new ticket is for: requests from the Helpdesk app name the person in Reply-To. */
class RequesterTest extends TestCase {
	public function testTheMarkerLineAloneIsEnough(): void {
		$mail = (new MimeParser())->parse(
			"From: noreply@firma.dk\r\nReply-To: kristian@firma.dk\r\nSubject: Hej\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n\r\nHej\r\n\r\nSent with Helpdesk for Nextcloud\r\n"
		);
		$this->assertSame('kristian@firma.dk', MailIntakeService::requester($mail)[0]);
	}

	public function testAHelpdeskRequestFromNoReplyIsNotAutomatic(): void {
		$this->assertFalse($this->mail("X-Nextcloud-Helpdesk: 1\r\n")->isAutomatic);
		// Ordinary mail from a no-reply address still is.
		$this->assertTrue($this->mail('')->isAutomatic);
	}

	private function mail(string $extraHeaders): \OCA\TicketSystem\Mail\ParsedMail {
		return (new MimeParser())->parse(
			"From: Firma Nextcloud <noreply@firma.dk>\r\n"
			. "To: support@provider.dk\r\n"
			. "Reply-To: Kristian Hansen <Kristian@firma.dk>\r\n"
			. $extraHeaders
			. "Subject: Printeren virker ikke\r\n"
			. "Message-ID: <req1@firma.dk>\r\n"
			. "Content-Type: text/plain; charset=utf-8\r\n\r\n"
			. "Hej\r\n"
		);
	}

	public function testHelpdeskRequestIsForThePersonInReplyTo(): void {
		$mail = $this->mail("X-Nextcloud-Helpdesk: 1.0.0\r\n");
		$this->assertSame(['kristian@firma.dk', 'Kristian Hansen'], MailIntakeService::requester($mail));
	}

	public function testAHelpdeskReplyIsFiledUnderReplyToOnlyWhenThatPersonIsOnTheTicket(): void {
		$mail = $this->mail("X-Nextcloud-Helpdesk: 1
");
		$this->assertSame('kristian@firma.dk', MailIntakeService::replyAuthor($mail, 'kristian@firma.dk', []));
		$this->assertSame('kristian@firma.dk', MailIntakeService::replyAuthor($mail, 'chef@firma.dk', ['kristian@firma.dk']));
		// Reply-To alone proves nothing: someone not on the ticket stays the real sender.
		$this->assertSame('noreply@firma.dk', MailIntakeService::replyAuthor($mail, 'karen@andet.dk', []));
		$this->assertSame('noreply@firma.dk', MailIntakeService::replyAuthor($this->mail(''), 'kristian@firma.dk', []));
	}

	public function testOrdinaryMailIsForTheSender(): void {
		$mail = $this->mail('');
		$this->assertSame(['noreply@firma.dk', 'Firma Nextcloud'], MailIntakeService::requester($mail));
	}
}
