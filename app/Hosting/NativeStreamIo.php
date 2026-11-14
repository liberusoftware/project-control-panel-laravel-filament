<?php

namespace App\Hosting;

final class NativeStreamIo implements StreamIo
{
    public function open(string $path, string $mode): mixed
    {
        return @fopen($path, $mode);
    }

    public function inspect(mixed $stream): array|false
    {
        return fstat($stream);
    }

    public function read(mixed $stream, int $maximumBytes): string|false
    {
        return stream_get_contents($stream, $maximumBytes);
    }

    public function write(mixed $stream, string $contents): int|false
    {
        return fwrite($stream, $contents);
    }

    public function flush(mixed $stream): bool
    {
        return fflush($stream);
    }

    public function sync(mixed $stream): bool
    {
        return fsync($stream);
    }

    public function close(mixed $stream): void
    {
        fclose($stream);
    }
}
