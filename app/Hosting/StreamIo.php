<?php

namespace App\Hosting;

interface StreamIo
{
    /** @return resource|false */
    public function open(string $path, string $mode): mixed;

    /**
     * @param  resource  $stream
     * @return array<array-key, int>|false
     */
    public function inspect(mixed $stream): array|false;

    /** @param resource $stream */
    public function read(mixed $stream, int $maximumBytes): string|false;

    /** @param resource $stream */
    public function write(mixed $stream, string $contents): int|false;

    /** @param resource $stream */
    public function flush(mixed $stream): bool;

    /** @param resource $stream */
    public function sync(mixed $stream): bool;

    /** @param resource $stream */
    public function close(mixed $stream): void;
}
