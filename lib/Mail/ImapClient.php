<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Co-Optimize
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\TicketSystem\Mail;

/**
 * A small IMAP client covering exactly what email intake needs: log in, pick a
 * folder, list unread messages, download one, and mark or move it.
 *
 * It talks the protocol directly over a socket, so it needs no PHP imap
 * extension (which most PHP builds, including Nextcloud's Docker image, no
 * longer ship). Supports implicit TLS (port 993), STARTTLS (143) and plain.
 */
class ImapClient {
	public const SECURITY_SSL = 'ssl';
	public const SECURITY_STARTTLS = 'starttls';
	public const SECURITY_NONE = 'none';

	/** @var resource|null */
	private $stream = null;
	private int $tagCounter = 0;
	/** @var string[] */
	private array $capabilities = [];
	/** @var string[]|null */
	private ?array $knownFolders = null;
	private ?int $uidValidity = null;

	public function __construct(
		private string $host,
		private int $port,
		private string $security = self::SECURITY_SSL,
		private bool $verifyTls = true,
		private int $timeout = 30,
	) {
	}

	public function __destruct() {
		if ($this->stream !== null) {
			@fclose($this->stream);
		}
	}

	/** @throws ImapException */
	public function connect(): void {
		$context = stream_context_create(['ssl' => [
			'verify_peer' => $this->verifyTls,
			'verify_peer_name' => $this->verifyTls,
			'allow_self_signed' => !$this->verifyTls,
			'SNI_enabled' => true,
			'peer_name' => $this->host,
		]]);
		$scheme = $this->security === self::SECURITY_SSL ? 'ssl' : 'tcp';
		$errno = 0;
		$errstr = '';
		$stream = @stream_socket_client(
			sprintf('%s://%s:%d', $scheme, $this->host, $this->port),
			$errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $context,
		);
		if ($stream === false) {
			throw new ImapException(sprintf('Could not connect to %s:%d: %s', $this->host, $this->port, $errstr ?: 'connection failed'));
		}
		stream_set_timeout($stream, $this->timeout);
		$this->stream = $stream;

		$greeting = $this->readLine();
		if (!str_starts_with($greeting, '* OK') && !str_starts_with($greeting, '* PREAUTH')) {
			throw new ImapException('Unexpected server greeting: ' . trim($greeting));
		}

		if ($this->security === self::SECURITY_STARTTLS) {
			$this->command('STARTTLS');
			$crypto = @stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
			if ($crypto !== true) {
				throw new ImapException('STARTTLS failed. Check the certificate, or the security setting.');
			}
		}
		$this->refreshCapabilities();
	}

	/** @throws ImapException */
	public function login(string $user, string $password): void {
		if (in_array('LOGINDISABLED', $this->capabilities, true)) {
			throw new ImapException('The server refuses passwords on an unencrypted connection. Use SSL/TLS or STARTTLS.');
		}
		try {
			$this->command('LOGIN ' . $this->astring($user) . ' ' . $this->astring($password));
		} catch (ImapException $e) {
			throw new ImapException('Login failed: ' . $e->getMessage());
		}
		$this->refreshCapabilities();
	}

	/**
	 * @return int number of messages in the folder
	 * @throws ImapException
	 */
	public function select(string $folder): int {
		$response = $this->command('SELECT ' . $this->quote($this->encodeFolder($folder)));
		$this->uidValidity = null;
		$exists = 0;
		foreach ($response as $line) {
			if (preg_match('/^\* (\d+) EXISTS/i', $line['text'], $m)) {
				$exists = (int)$m[1];
			} elseif (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line['text'], $m)) {
				$this->uidValidity = (int)$m[1];
			}
		}
		return $exists;
	}

	/**
	 * The selected folder's UIDVALIDITY: while it stays the same, UIDs only
	 * grow, so "everything after UID n" is exactly the new mail.
	 */
	public function uidValidity(): ?int {
		return $this->uidValidity;
	}

	/**
	 * @return int[] UIDs after $uid, read or not, oldest first
	 * @throws ImapException
	 */
	public function searchAfter(int $uid): array {
		// "n:*" also answers with the newest message when nothing is newer than n.
		return array_values(array_filter($this->search('UID SEARCH UID ' . ($uid + 1) . ':*'), fn (int $u) => $u > $uid));
	}

	/**
	 * @return int[] UIDs of unread messages, oldest first
	 * @throws ImapException
	 */
	public function searchUnseen(): array {
		return $this->search('UID SEARCH UNSEEN');
	}

	/**
	 * @return int[] sorted UIDs from a UID SEARCH
	 * @throws ImapException
	 */
	private function search(string $command): array {
		$uids = [];
		foreach ($this->command($command) as $line) {
			if (preg_match('/^\* SEARCH\b(.*)$/i', $line['text'], $m)) {
				foreach (preg_split('/\s+/', trim($m[1])) as $uid) {
					if (ctype_digit($uid)) {
						$uids[] = (int)$uid;
					}
				}
			}
		}
		sort($uids);
		return $uids;
	}

	/** @throws ImapException */
	public function fetchSize(int $uid): ?int {
		foreach ($this->command("UID FETCH $uid (RFC822.SIZE)") as $line) {
			if (preg_match('/RFC822\.SIZE (\d+)/i', $line['text'], $m)) {
				return (int)$m[1];
			}
		}
		return null;
	}

	/**
	 * The complete raw message, without marking it as read.
	 * @throws ImapException
	 */
	public function fetchRaw(int $uid): string {
		return $this->fetchSection($uid, 'BODY.PEEK[]');
	}

	/** Only the header block, for messages too large to download. */
	public function fetchHeader(int $uid): string {
		return $this->fetchSection($uid, 'BODY.PEEK[HEADER]');
	}

	/** @throws ImapException */
	public function addFlags(int $uid, array $flags): void {
		$this->command("UID STORE $uid +FLAGS.SILENT (" . implode(' ', $flags) . ')');
	}

	/**
	 * Move a message to another folder, creating the folder when needed.
	 * @throws ImapException
	 */
	public function move(int $uid, string $folder): void {
		$encoded = $this->quote($this->encodeFolder($folder));
		$this->ensureFolder($folder);
		if (in_array('MOVE', $this->capabilities, true)) {
			$this->command("UID MOVE $uid $encoded");
			return;
		}
		$this->command("UID COPY $uid $encoded");
		$this->addFlags($uid, ['\\Deleted']);
		$this->command(in_array('UIDPLUS', $this->capabilities, true) ? "UID EXPUNGE $uid" : 'EXPUNGE');
	}

	/**
	 * @return string[] folder names, for the connection test
	 * @throws ImapException
	 */
	public function listFolders(): array {
		$folders = [];
		foreach ($this->command('LIST "" "*"') as $line) {
			$name = null;
			if ($line['literals'] !== []) {
				$name = end($line['literals']);
			} elseif (preg_match('/^\* LIST \([^)]*\) (?:"[^"]*"|NIL) (.+)$/i', $line['text'], $m)) {
				$name = trim($m[1]);
				if (str_starts_with($name, '"')) {
					$name = stripcslashes(substr($name, 1, -1));
				}
			}
			if ($name !== null) {
				$folders[] = mb_convert_encoding($name, 'UTF-8', 'UTF7-IMAP');
			}
		}
		return $folders;
	}

	/** Creates the folder unless it exists (checked once per connection). */
	private function ensureFolder(string $folder): void {
		if ($this->knownFolders === null) {
			$this->knownFolders = $this->listFolders();
		}
		if (in_array($folder, $this->knownFolders, true)) {
			return;
		}
		$this->command('CREATE ' . $this->quote($this->encodeFolder($folder)));
		$this->knownFolders[] = $folder;
	}

	public function logout(): void {
		if ($this->stream === null) {
			return;
		}
		try {
			$this->command('LOGOUT');
		} catch (\Throwable) {
		}
		@fclose($this->stream);
		$this->stream = null;
	}

	private function fetchSection(int $uid, string $section): string {
		foreach ($this->command("UID FETCH $uid ($section)") as $line) {
			if (stripos($line['text'], 'FETCH') !== false && $line['literals'] !== []) {
				return $line['literals'][0];
			}
		}
		throw new ImapException("Message $uid could not be downloaded");
	}

	private function refreshCapabilities(): void {
		foreach ($this->command('CAPABILITY') as $line) {
			if (preg_match('/^\* CAPABILITY (.+)$/i', $line['text'], $m)) {
				$this->capabilities = array_map('strtoupper', preg_split('/\s+/', trim($m[1])));
			}
		}
	}

	/**
	 * Send a command and collect the untagged responses until its tagged result.
	 *
	 * @return list<array{text: string, literals: string[]}>
	 * @throws ImapException on NO/BAD or connection problems
	 */
	private function command(string $command): array {
		if ($this->stream === null) {
			throw new ImapException('Not connected');
		}
		$tag = sprintf('H%04d', ++$this->tagCounter);
		$this->write($tag . ' ' . $command . "\r\n");

		$untagged = [];
		while (true) {
			[$text, $literals] = $this->readResponse();
			if (str_starts_with($text, $tag . ' ')) {
				$result = substr($text, strlen($tag) + 1);
				if (stripos($result, 'OK') === 0) {
					return $untagged;
				}
				throw new ImapException(trim(preg_replace('/^(NO|BAD)\s*/i', '', $result) ?? $result) ?: 'Command failed');
			}
			if (str_starts_with($text, '+')) {
				// Continuation request; this client never sends synchronizing literals.
				throw new ImapException('Unexpected continuation from server');
			}
			$untagged[] = ['text' => $text, 'literals' => $literals];
		}
	}

	/**
	 * One logical response line. Literals ("{123}\r\n<123 bytes>") are pulled
	 * out into $literals and replaced by a placeholder in the text.
	 *
	 * @return array{0: string, 1: string[]}
	 */
	private function readResponse(): array {
		$text = '';
		$literals = [];
		while (true) {
			$line = $this->readLine();
			if (preg_match('/\{(\d+)\+?\}\r\n$/', $line, $m)) {
				$text .= substr($line, 0, -strlen($m[0])) . '{literal}';
				$literals[] = $this->readBytes((int)$m[1]);
				continue;
			}
			$text .= rtrim($line, "\r\n");
			return [$text, $literals];
		}
	}

	private function readLine(): string {
		$line = fgets($this->stream);
		if ($line === false) {
			$meta = stream_get_meta_data($this->stream);
			throw new ImapException($meta['timed_out'] ? 'The mail server stopped responding' : 'Connection closed by the mail server');
		}
		return $line;
	}

	private function readBytes(int $length): string {
		$data = '';
		while (strlen($data) < $length) {
			$chunk = fread($this->stream, min(65536, $length - strlen($data)));
			if ($chunk === false || $chunk === '') {
				$meta = stream_get_meta_data($this->stream);
				if ($meta['timed_out'] || feof($this->stream)) {
					throw new ImapException('Connection lost while downloading a message');
				}
				continue;
			}
			$data .= $chunk;
		}
		return $data;
	}

	private function write(string $data): void {
		$written = 0;
		while ($written < strlen($data)) {
			$n = fwrite($this->stream, substr($data, $written));
			if ($n === false || $n === 0) {
				throw new ImapException('Could not send to the mail server');
			}
			$written += $n;
		}
	}

	/**
	 * Quoted string, or a non-synchronizing literal for non-ASCII values when
	 * the server supports LITERAL+ (passwords with æøå and the like).
	 */
	private function astring(string $value): string {
		if (preg_match('/[\x80-\xff]/', $value) && in_array('LITERAL+', $this->capabilities, true)) {
			return '{' . strlen($value) . "+}\r\n" . $value;
		}
		return $this->quote($value);
	}

	/** IMAP quoted string. Values with line breaks cannot be quoted and are refused. */
	private function quote(string $value): string {
		if (preg_match('/[\r\n\0]/', $value)) {
			throw new ImapException('Value contains line breaks');
		}
		return '"' . addcslashes($value, '"\\') . '"';
	}

	/** Folder names use "modified UTF-7" on the wire (RFC 3501). */
	private function encodeFolder(string $folder): string {
		return mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
	}
}
