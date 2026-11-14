<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Tests\Unit\Mail;

use OCA\TicketSystem\Mail\ThreadIds;
use PHPUnit\Framework\TestCase;

class ThreadIdsTest extends TestCase {
	private ThreadIds $ids;

	protected function setUp(): void {
		$this->ids = new ThreadIds('server-secret');
	}

	public function testNewIdsNameTheirTicketAndAreUnique(): void {
		$a = $this->ids->newId(42, 'ticketsystem.test');
		$b = $this->ids->newId(42, 'ticketsystem.test');
		$this->assertNotSame($a, $b);
		$this->assertMatchesRegularExpression('/^ticketsystem\.42\.[0-9a-f]{12}\.[0-9a-f]{20}@ticketsystem\.test$/', $a);
		$this->assertSame(42, $this->ids->ticketIdFrom([$a]));
	}

	public function testRootIdIsStable(): void {
		$root = $this->ids->rootId(7, 'ticketsystem.test');
		$this->assertSame($root, $this->ids->rootId(7, 'ticketsystem.test'));
		$this->assertSame(7, $this->ids->ticketIdFrom([$root]));
	}

	public function testFindsOurIdAmongOthersWithAngleBracketsAndChangedCase(): void {
		$ours = $this->ids->newId(42, 'ticketsystem.test');
		$this->assertSame(42, $this->ids->ticketIdFrom(['CAF123@mail.gmail.com', '<' . strtoupper($ours) . '>']));
	}

	public function testOtherDomainStillCounts(): void {
		// The mail domain may change after an email went out.
		$id = $this->ids->newId(42, 'old.example');
		$this->assertSame(42, $this->ids->ticketIdFrom([$id]));
	}

	public function testRejectsForgedAndForeignIds(): void {
		$real = $this->ids->newId(42, 'ticketsystem.test');
		$otherTicket = preg_replace('/^ticketsystem\.42\./', 'ticketsystem.43.', $real);
		$this->assertNull($this->ids->ticketIdFrom([$otherTicket]));
		$this->assertNull((new ThreadIds('another-server'))->ticketIdFrom([$real]));
		$this->assertNull($this->ids->ticketIdFrom(['ticketsystem.42.ticket.00000000000000000000@ticketsystem.test']));
		$this->assertNull($this->ids->ticketIdFrom(['CAF123@mail.gmail.com', '']));
		$this->assertNull($this->ids->ticketIdFrom([]));
	}

	public function testDomainOf(): void {
		$this->assertSame('ticketsystem.test', ThreadIds::domainOf('noreply@Ticketsystem.test'));
		$this->assertSame('localhost', ThreadIds::domainOf('noreply'));
		$this->assertSame('localhost', ThreadIds::domainOf('x@bad domain>'));
	}
}
