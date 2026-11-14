<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Tests\Unit\Service;

use OCA\TicketSystem\Service\AttachmentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttachmentNamesTest extends TestCase {
	/** @return array<string, array{string, string}> */
	public static function names(): array {
		return [
			'plain' => ['faktura.pdf', 'faktura.pdf'],
			'danish' => ['skærmbillede æøå.png', 'skærmbillede æøå.png'],
			'unix path' => ['../../etc/passwd', 'passwd'],
			'windows path' => ['C:\\Users\\karen\\billede.jpg', 'billede.jpg'],
			'control characters and quotes' => ["evil\n\"name\".exe", 'evilname.exe'],
			'reserved characters' => ['a<b>c:d|e?f*.txt', 'abcdef.txt'],
			'only dots' => ['..', 'attachment'],
			'empty' => ['', 'attachment'],
			'trailing dot and space' => ['rapport.docx. ', 'rapport.docx'],
		];
	}

	#[DataProvider('names')]
	public function testCleanFilename(string $in, string $out): void {
		$this->assertSame($out, AttachmentService::cleanFilename($in));
	}

	public function testLongNamesKeepTheirExtension(): void {
		$clean = AttachmentService::cleanFilename(str_repeat('æ', 300) . '.pdf');
		$this->assertLessThanOrEqual(255, strlen($clean));
		$this->assertStringEndsWith('.pdf', $clean);
		$this->assertTrue(mb_check_encoding($clean, 'UTF-8'));
	}

	public function testCleanMimeType(): void {
		$this->assertSame('image/png', AttachmentService::cleanMimeType('Image/PNG'));
		$this->assertSame('text/plain', AttachmentService::cleanMimeType('text/plain; charset=utf-8'));
		$this->assertSame('application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			AttachmentService::cleanMimeType('application/vnd.openxmlformats-officedocument.wordprocessingml.document'));
		$this->assertSame('application/octet-stream', AttachmentService::cleanMimeType('text/html<script>'));
		$this->assertSame('application/octet-stream', AttachmentService::cleanMimeType(''));
	}
}
