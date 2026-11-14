<?php

use App\Hosting\NativeStreamIo;
use App\Hosting\ProtectedFile;
use App\Hosting\StreamIo;

it('reads bounded operator-private files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hosting-private-');
    try {
        chmod($path, 0600);
        file_put_contents($path, 'private-value');
        expect(ProtectedFile::read($path, 64))->toBe('private-value');
        expect(fn () => ProtectedFile::read($path, 4))->toThrow(InvalidArgumentException::class);
        chmod($path, 0644);
        expect(fn () => ProtectedFile::read($path, 64))->toThrow(InvalidArgumentException::class);
    } finally {
        unlink($path);
    }
});

it('enforces host-file privacy and non-root Kubernetes Secret mount permissions', function (int $owner, int $group, int $mode, bool $allowed): void {
    $stat = ['uid' => $owner, 'gid' => $group, 'mode' => 0100000 | $mode, 'nlink' => 1];
    expect(ProtectedFile::hasPrivatePermissions($stat, 1000, [1000, 2000]))->toBe($allowed);
})->with([
    'private owner' => [1000, 1000, 0600, true],
    'read-only owner' => [1000, 1000, 0400, true],
    'exposed owner file' => [1000, 1000, 0640, false],
    'root-owned primary group Secret' => [0, 1000, 0440, true],
    'root-owned supplementary group Secret' => [0, 2000, 0440, true],
    'unrelated group' => [0, 3000, 0440, false],
    'world-readable Secret' => [0, 1000, 0444, false],
    'group-writable Secret' => [0, 1000, 0460, false],
    'foreign owner' => [2000, 1000, 0400, false],
]);

it('rejects symbolic and hard-linked credential files', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hosting-private-');
    $symbolic = $path.'-symbolic';
    $hard = $path.'-hard';
    try {
        chmod($path, 0600);
        file_put_contents($path, 'private-value');
        symlink($path, $symbolic);
        expect(fn () => ProtectedFile::read($symbolic, 64))->toThrow(InvalidArgumentException::class);
        link($path, $hard);
        expect(fn () => ProtectedFile::read($path, 64))->toThrow(InvalidArgumentException::class);
    } finally {
        @unlink($symbolic);
        @unlink($hard);
        unlink($path);
    }
});

it('rejects unbounded reads and relative credential paths', function (string $path, int $limit): void {
    expect(fn () => ProtectedFile::read($path, $limit))
        ->toThrow(InvalidArgumentException::class, 'Choose an absolute protected file path and a bounded byte limit.');
})->with([
    ['relative-secret', 64],
    ['/nonexistent/secret', 0],
    ['/nonexistent/secret', 8 * 1024 * 1024 + 1],
]);

it('rejects a private file whose owner cannot read its contents', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'hosting-unreadable-');
    try {
        file_put_contents($path, 'protected-value');
        chmod($path, 0000);
        expect(fn () => ProtectedFile::read($path, 64))
            ->toThrow(InvalidArgumentException::class, 'The protected file is unreadable.');
    } finally {
        chmod($path, 0600);
        unlink($path);
    }
});

it('rejects stream metadata changed after the protected path check', function (string $fault): void {
    $path = tempnam(sys_get_temp_dir(), 'hosting-race-');
    chmod($path, 0600);
    file_put_contents($path, 'private-value');
    $metadata = lstat($path);
    $observed = match ($fault) {
        'unavailable' => false,
        'replaced inode' => [...$metadata, 'ino' => $metadata['ino'] + 1],
        'unsafe permissions' => [...$metadata, 'mode' => 0100644],
    };
    $native = new NativeStreamIo();
    $streams = Mockery::mock(StreamIo::class);
    $streams->shouldReceive('open')->once()->with($path, 'rb')->andReturnUsing($native->open(...));
    $streams->shouldReceive('inspect')->once()->andReturn($observed);
    $streams->shouldReceive('close')->once()->andReturnUsing($native->close(...));
    try {
        expect(fn () => ProtectedFile::read($path, 64, $streams))
            ->toThrow(InvalidArgumentException::class, 'The protected file changed or became an unsafe link.');
    } finally {
        unlink($path);
    }
})->with(['unavailable', 'replaced inode', 'unsafe permissions']);

it('closes protected streams when reading fails or the file grows beyond the checked size', function (string|false $contents): void {
    $path = tempnam(sys_get_temp_dir(), 'hosting-read-failure-');
    chmod($path, 0600);
    file_put_contents($path, 'short');
    $native = new NativeStreamIo();
    $streams = Mockery::mock(StreamIo::class);
    $streams->shouldReceive('open')->once()->andReturnUsing($native->open(...));
    $streams->shouldReceive('inspect')->once()->andReturnUsing($native->inspect(...));
    $streams->shouldReceive('read')->once()->with(Mockery::type('resource'), 9)->andReturn($contents);
    $streams->shouldReceive('close')->once()->andReturnUsing($native->close(...));
    try {
        expect(fn () => ProtectedFile::read($path, 8, $streams))
            ->toThrow(InvalidArgumentException::class, 'The protected file exceeds its byte limit.');
    } finally {
        unlink($path);
    }
})->with(['failed read' => [false], 'grew during read' => ['123456789']]);
