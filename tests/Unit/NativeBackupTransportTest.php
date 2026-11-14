<?php

use App\Hosting\Migrations\NativeBackupTransport;
use App\Hosting\NativeStreamIo;
use App\Hosting\StreamIo;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/native-transfer-'.bin2hex(random_bytes(8));
    mkdir($this->workspace, 0700);
    $this->workspace = realpath($this->workspace);
    $this->identity = $this->workspace.'/identity';
    $this->knownHosts = $this->workspace.'/known-hosts';
    file_put_contents($this->identity, 'PRIVATE-IDENTITY-FIXTURE');
    file_put_contents($this->knownHosts, 'source.example.test ssh-ed25519 public-fixture');
    chmod($this->identity, 0600);
    chmod($this->knownHosts, 0600);
    $this->connection = ['host' => 'source.example.test', 'username' => 'migration', 'identity_file' => $this->identity, 'known_hosts_file' => $this->knownHosts];
    $this->data = str_repeat('native-backup-data-', 1000);
    $this->archive = ['domain' => 'example.test', 'bytes' => strlen($this->data), 'sha256' => hash('sha256', $this->data)];
    $this->payload = $this->data;
    $this->exitCode = 0;
    $this->errorOutput = '';
    $this->arguments = [];
    $this->processFactory = function (array $arguments): Process {
        $this->arguments = $arguments;

        // Exercise the real process streaming/exit-status path without invoking SSH or connecting anywhere.
        return new Process([PHP_BINARY, '-r', 'file_put_contents($argv[4], stream_get_contents(STDIN)); fwrite(STDOUT, base64_decode($argv[1])); fwrite(STDERR, $argv[2]); exit((int) $argv[3]);',
            base64_encode($this->payload), $this->errorOutput, (string) $this->exitCode, $this->workspace.'/request.json']);
    };
    $this->transport = new NativeBackupTransport($this->processFactory);
});

afterEach(function (): void {
    (new Filesystem())->deleteDirectory($this->workspace);
});

it('streams a checksum-pinned native archive privately and publishes only after successful verification', function (): void {
    $destination = $this->workspace.'/received.native';
    $result = $this->transport->download($this->connection, $this->archive, $destination);
    expect($result)->toMatchArray(['bytes' => strlen($this->data), 'sha256' => hash('sha256', $this->data)])
        ->and(file_get_contents($destination))->toBe($this->data)->and(fileperms($destination) & 0777)->toBe(0600)
        ->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
    $request = json_decode(file_get_contents($this->workspace.'/request.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($request)->toBe(['operation' => 'stream', ...$this->archive]);
});

it('pins SSH identities and host keys and disables inherited configs, agents and forwarding', function (): void {
    $this->transport->download($this->connection, $this->archive, $this->workspace.'/received.native');
    expect($this->arguments)->toContain('StrictHostKeyChecking=yes', 'IdentityAgent=none', 'IdentitiesOnly=yes', 'BatchMode=yes',
        'ClearAllForwardings=yes', 'ForwardAgent=no', 'ProxyCommand=none', 'ProxyJump=none', 'GlobalKnownHostsFile=/dev/null',
        'UserKnownHostsFile='.$this->knownHosts, '/usr/local/libexec/control-panel-migration-source')
        ->and(implode(' ', $this->arguments))->not->toContain('PRIVATE-IDENTITY-FIXTURE');
});

it('discards failed, truncated, oversized or checksum-mismatched streams without publishing any destination', function (string $failure): void {
    match ($failure) {
        'nonzero-exit' => $this->exitCode = 1,
        'truncated' => $this->payload = substr($this->data, 1),
        'oversized' => $this->payload .= 'extra',
        'wrong-checksum' => $this->payload = str_repeat('x', strlen($this->data)),
    };
    $this->errorOutput = 'private-upstream-error';
    $destination = $this->workspace.'/received.native';
    try {
        $this->transport->download($this->connection, $this->archive, $destination);
        $this->fail('Invalid source stream must not be accepted.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->not->toContain('private-upstream-error');
    }
    expect(file_exists($destination))->toBeFalse()->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
})->with(['nonzero-exit', 'truncated', 'oversized', 'wrong-checksum']);

it('never overwrites an existing staging artifact', function (): void {
    $destination = $this->workspace.'/existing.native';
    file_put_contents($destination, 'keep-existing-data');
    expect(fn () => $this->transport->download($this->connection, $this->archive, $destination))->toThrow(InvalidArgumentException::class);
    expect(file_get_contents($destination))->toBe('keep-existing-data')->and($this->arguments)->toBe([]);
});

it('rejects public staging directories before opening an SSH connection', function (): void {
    chmod($this->workspace, 0755);
    expect(fn () => $this->transport->download($this->connection, $this->archive, $this->workspace.'/received.native'))->toThrow(InvalidArgumentException::class);
    expect($this->arguments)->toBe([]);
});

it('rejects invalid SSH destinations or exposed identity files before starting any process', function (string $problem): void {
    match ($problem) {
        'option-injection' => $this->connection['host'] = '-oProxyCommand=anything',
        'username-injection' => $this->connection['username'] = 'root;anything',
        'plaintext-url' => $this->connection['host'] = 'http://source.example.test',
        'invalid-port' => $this->connection['port'] = 70000,
        'exposed-key' => chmod($this->identity, 0644),
    };
    expect(fn () => $this->transport->manifest($this->connection))->toThrow(InvalidArgumentException::class);
    expect($this->arguments)->toBe([]);
})->with(['option-injection', 'username-injection', 'plaintext-url', 'invalid-port', 'exposed-key']);

it('returns only a bounded sanitized manifest and never claims a provider import', function (): void {
    $this->payload = json_encode(['format' => 'native-backup-manifest-v1', 'provider' => 'virtualmin8', 'archives' => [$this->archive], 'imported' => false, 'unexpected_secret' => 'private-source-secret'], JSON_THROW_ON_ERROR);
    $manifest = $this->transport->manifest($this->connection);
    expect($manifest['archives'])->toBe([$this->archive])->and($manifest['imported'])->toBeFalse()
        ->and(json_encode($manifest))->not->toContain('private-source-secret');
});

it('refuses malformed, duplicated or oversized source manifests', function (string $failure): void {
    $manifest = ['format' => 'native-backup-manifest-v1', 'provider' => 'virtualmin8', 'archives' => [$this->archive], 'imported' => false];
    match ($failure) {
        'duplicate' => $manifest['archives'][] = $this->archive,
        'wrong-format' => $manifest['format'] = 'unexpected',
        'unbounded-archive' => $manifest['archives'][0]['bytes'] = 1099511627777,
        'false-import-claim' => $manifest['imported'] = true,
    };
    $this->payload = json_encode($manifest, JSON_THROW_ON_ERROR);
    expect(fn () => $this->transport->manifest($this->connection))->toThrow(Exception::class);
})->with(['duplicate', 'wrong-format', 'unbounded-archive', 'false-import-claim']);

it('stops an overflowing stream promptly and never publishes partial data', function (): void {
    $process = new Process([PHP_BINARY, '-r', 'while (true) { fwrite(STDOUT, str_repeat("x", 65536)); }']);
    $transport = new NativeBackupTransport(fn (array $arguments): Process => $process);
    $this->connection['timeout'] = 1;
    $destination = $this->workspace.'/oversized.native';
    expect(fn () => $transport->download($this->connection, $this->archive, $destination))->toThrow(RuntimeException::class);
    expect($process->isRunning())->toBeFalse()
        ->and(file_exists($destination))->toBeFalse()->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
});

it('rejects malformed JSON without echoing source output', function (): void {
    $this->payload = '{private-source-secret';
    expect(fn () => $this->transport->manifest($this->connection))
        ->toThrow(RuntimeException::class, 'The source returned an invalid native backup manifest.');
});

it('bounds manifest output while consuming the source stream', function (): void {
    $process = new Process([PHP_BINARY, '-r', 'stream_get_contents(STDIN); fwrite(STDOUT, str_repeat("x", 1048577));']);
    $transport = new NativeBackupTransport(fn (array $arguments): Process => $process);
    expect(fn () => $transport->manifest($this->connection))->toThrow(RuntimeException::class, 'Source transport failed');
    expect($process->isRunning())->toBeFalse();
});

it('requires protected SSH file bindings and valid domain labels', function (string $fault): void {
    if ($fault === 'missing identity') {
        unset($this->connection['identity_file']);
        expect(fn () => $this->transport->manifest($this->connection))->toThrow(InvalidArgumentException::class, 'Provide protected SSH identity');
    } else {
        $this->archive['domain'] = '-invalid.example.test';
        expect(fn () => $this->transport->download($this->connection, $this->archive, $this->workspace.'/received.native'))
            ->toThrow(InvalidArgumentException::class, 'invalid hostname label');
    }
    expect($this->arguments)->toBe([]);
})->with(['missing identity', 'invalid label']);

it('rejects collections larger than available staging capacity before starting a process', function (): void {
    $this->archive['bytes'] = (int) disk_free_space($this->workspace) + 1;
    $this->connection['max_archive_bytes'] = 1099511627776;
    expect(fn () => $this->transport->download($this->connection, $this->archive, $this->workspace.'/received.native'))
        ->toThrow(RuntimeException::class, 'Insufficient staging space');
    expect($this->arguments)->toBe([])->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
});

it('preserves an artifact created concurrently before exclusive publication', function (): void {
    $destination = $this->workspace.'/received.native';
    $transport = new NativeBackupTransport(function (array $arguments) use ($destination): Process {
        file_put_contents($destination, 'concurrent-artifact');

        return new Process([PHP_BINARY, '-r', 'stream_get_contents(STDIN); fwrite(STDOUT, base64_decode($argv[1]));', base64_encode($this->data)]);
    });
    expect(fn () => $transport->download($this->connection, $this->archive, $destination))
        ->toThrow(RuntimeException::class, 'The destination already exists or cannot be published safely.');
    expect(file_get_contents($destination))->toBe('concurrent-artifact')
        ->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
});

it('bounds stderr without printing provider details or leaving partial staging', function (): void {
    $this->errorOutput = str_repeat('private-error', 6000);
    $destination = $this->workspace.'/received.native';
    expect(fn () => $this->transport->download($this->connection, $this->archive, $destination))
        ->toThrow(RuntimeException::class, 'Source transport failed identity, timeout, byte-limit or integrity validation. Partial data was discarded; SSH output and credentials were not printed.');
    expect(file_exists($destination))->toBeFalse()->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
});

it('ignores output drained while stopping a failed transport and discards partial staging', function (): void {
    $callback = null;
    $process = Mockery::mock(Process::class);
    $process->shouldReceive('setTimeout')->once()->with(1800)->andReturnSelf();
    $process->shouldReceive('setInput')->once()->with(json_encode(['operation' => 'stream', ...$this->archive], JSON_THROW_ON_ERROR)."\n")->andReturnSelf();
    $process->shouldReceive('disableOutput')->once()->andReturnSelf();
    $process->shouldReceive('run')->once()->andReturnUsing(function (Closure $consume) use (&$callback): never {
        $callback = $consume;
        $consume(Process::OUT, substr($this->data, 0, 10));

        throw new RuntimeException('private-process-failure');
    });
    $process->shouldReceive('isRunning')->once()->andReturnTrue();
    $process->shouldReceive('stop')->once()->with(0.1)->andReturnUsing(function () use (&$callback): int {
        $callback(Process::OUT, str_repeat('x', $this->archive['bytes'] + 1));
        $callback(Process::ERR, str_repeat('private-error', 6000));

        return 1;
    });
    $destination = $this->workspace.'/received.native';
    $transport = new NativeBackupTransport(fn (array $arguments): Process => $process);

    expect(fn () => $transport->download($this->connection, $this->archive, $destination))
        ->toThrow(RuntimeException::class, 'Source transport failed identity, timeout, byte-limit or integrity validation. Partial data was discarded; SSH output and credentials were not printed.');
    expect(file_exists($destination))->toBeFalse()->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
});

it('discards staging when the operating system rejects a streamed file write', function (): void {
    $limits = posix_getrlimit();
    $soft = $limits['soft filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['soft filesize'];
    $hard = $limits['hard filesize'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['hard filesize'];
    $previousSignal = pcntl_signal_get_handler(SIGXFSZ);
    $warnings = [];
    $destination = $this->workspace.'/received.native';
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if (in_array($severity, [E_WARNING, E_NOTICE], true) && str_contains($message, 'fwrite(')) {
            $warnings[] = $message;

            return true;
        }

        return false;
    });
    try {
        pcntl_signal(SIGXFSZ, SIG_IGN);
        if (! posix_setrlimit(POSIX_RLIMIT_FSIZE, 1, $hard)) {
            throw new RuntimeException('Unable to set the process-local write limit.');
        }
        try {
            $this->transport->download($this->connection, $this->archive, $destination);
            $this->fail('Failed staging writes must not be published.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('Source transport failed');
        }
    } finally {
        posix_setrlimit(POSIX_RLIMIT_FSIZE, $soft, $hard);
        pcntl_signal(SIGXFSZ, $previousSignal);
        restore_error_handler();
    }
    expect($warnings)->not->toBeEmpty()->and(file_exists($destination))->toBeFalse()
        ->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
});

it('fails closed when the process cannot allocate a protected staging file', function (): void {
    $limits = posix_getrlimit();
    $soft = $limits['soft openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['soft openfiles'];
    $hard = $limits['hard openfiles'] === 'unlimited' ? POSIX_RLIMIT_INFINITY : $limits['hard openfiles'];
    $destination = $this->workspace.'/received.native';
    $failure = null;
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if (in_array($severity, [E_WARNING, E_NOTICE], true) && str_contains($message, 'tempnam(')) {
            $warnings[] = $message;

            return true;
        }

        return false;
    });
    try {
        if (! posix_setrlimit(POSIX_RLIMIT_NOFILE, 0, $hard)) {
            throw new RuntimeException('Unable to set the process-local file limit.');
        }
        try {
            $this->transport->download($this->connection, $this->archive, $destination);
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }
    } finally {
        posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, $hard);
        restore_error_handler();
    }
    expect($failure)->toBeInstanceOf(RuntimeException::class)
        ->and($failure->getMessage())->toBe('Unable to create a protected transfer file.')
        ->and($warnings)->not->toBeEmpty()
        ->and($this->arguments)->toBe([])->and(file_exists($destination))->toBeFalse()
        ->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
});

it('never publishes or retains temporary files after stream open or durability failure', function (string $operation, string $message): void {
    $native = new NativeStreamIo();
    $streams = Mockery::mock(StreamIo::class);
    foreach (['open', 'write', 'flush', 'sync', 'close'] as $method) {
        $streams->shouldReceive($method)->andReturnUsing(static fn (mixed ...$arguments): mixed => $native->$method(...$arguments))->byDefault();
    }
    $streams->shouldReceive($operation)->once()->andReturn(false);
    $destination = $this->workspace.'/received.native';
    $transport = new NativeBackupTransport($this->processFactory, $streams);
    expect(fn () => $transport->download($this->connection, $this->archive, $destination))->toThrow(RuntimeException::class, $message);
    expect(file_exists($destination))->toBeFalse()->and(glob($this->workspace.'/.receiving-*'))->toBe([]);
    if ($operation === 'open') {
        expect($this->arguments)->toBe([]);
    }
})->with([
    ['open', 'Unable to open a protected transfer file.'],
    ['flush', 'The received archive could not be synchronized.'],
    ['sync', 'The received archive could not be synchronized.'],
]);
