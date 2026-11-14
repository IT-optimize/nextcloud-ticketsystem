<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Tests\Unit\Mail;

use OCA\TicketSystem\Mail\Names;
use PHPUnit\Framework\TestCase;

class NamesTest extends TestCase {
	public function testCleanDropsCompanyAndTitle(): void {
		$this->assertSame('Anna Jensen', Names::clean('Anna Jensen | Firma A/S'));
		$this->assertSame('Karen Kunde', Names::clean('"Karen Kunde - Indkøb"'));
		$this->assertSame('Ole Hansen', Names::clean('Ole Hansen (Firma A/S)'));
		$this->assertSame('Hans-Peter Jensen', Names::clean('Hans-Peter Jensen'));
		$this->assertSame('Jensen, Anna', Names::clean('Jensen, Anna'));
		$this->assertNull(Names::clean('  '));
		$this->assertNull(Names::clean(null));
	}

	public function testFirstName(): void {
		$this->assertSame('Anna', Names::first('Anna Jensen | Firma A/S'));
		$this->assertSame('Anna', Names::first('Jensen, Anna'));
		$this->assertSame('Hans-Peter', Names::first('Hans-Peter Jensen'));
		$this->assertSame('Karen', Names::first('Karen'));
		$this->assertNull(Names::first('karen@kunde.dk'));
		$this->assertNull(Names::first('K'));
		$this->assertNull(Names::first(null));
	}
}
