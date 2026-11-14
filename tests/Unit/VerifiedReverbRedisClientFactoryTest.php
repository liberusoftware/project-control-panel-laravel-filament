<?php

use App\Hosting\Kubernetes\VerifiedReverbRedisClientFactory;
use Clue\React\Redis\Client;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Laravel\Reverb\Servers\Reverb\Publishing\RedisClientFactory;
use React\EventLoop\StreamSelectLoop;
use React\Socket\ConnectionInterface;
use React\Socket\SecureServer;
use React\Socket\TcpServer;

beforeEach(function (): void {
    $this->workspace = sys_get_temp_dir().'/reverb-tls-'.bin2hex(random_bytes(8));
    mkdir($this->workspace, 0700);
    foreach (['server', 'untrusted'] as $name) {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'localhost'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($request, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export_to_file($certificate, $this->workspace.'/'.$name.'.pem');
        openssl_pkey_export_to_file($key, $this->workspace.'/'.$name.'.key');
    }
});

afterEach(function (): void {
    (new Filesystem())->deleteDirectory($this->workspace);
});

it('binds the verified factory without capturing a request or tenant', function (): void {
    expect(app(RedisClientFactory::class))->toBeInstanceOf(VerifiedReverbRedisClientFactory::class)
        ->and(app(RedisClientFactory::class))->not->toBe(app(RedisClientFactory::class));
});

it('uses TLS, verifies trust and hostname, and authenticates the configured ACL user before SELECT', function (string $scenario): void {
    $loop = new StreamSelectLoop();
    $tcp = new TcpServer('127.0.0.1:0', $loop);
    $server = new SecureServer($tcp, $loop, ['local_cert' => $this->workspace.'/server.pem', 'local_pk' => $this->workspace.'/server.key']);
    $connections = [];
    $requests = [];
    $server->on('error', static function (Throwable $exception): void {});
    $server->on('connection', function (ConnectionInterface $connection) use (&$connections, &$requests, $scenario): void {
        $connections[] = $connection;
        $connection->on('data', function (string $data) use ($connection, &$requests, $scenario): void {
            $requests[] = $data;
            $connection->write($scenario === 'authentication-failure' ? "-WRONGPASS private-upstream-detail\r\n" : "+OK\r\n");
        });
    });
    $config = new Repository([
        'reverb' => ['servers' => ['reverb' => ['scaling' => ['server' => ['scheme' => 'tls']]]]],
        'database' => ['redis' => ['default' => ['context' => ['ssl' => ['cafile' => $this->workspace.'/'.($scenario === 'wrong-ca' ? 'untrusted' : 'server').'.pem']]]]],
    ]);
    $port = parse_url($tcp->getAddress(), PHP_URL_PORT);
    $host = $scenario === 'wrong-name' ? '127.0.0.1' : 'localhost';
    $client = null;
    $failure = null;
    $timer = $loop->addTimer(5, static function () use ($loop, &$failure): void {
        $failure = new RuntimeException('Local TLS test did not finish.');
        $loop->stop();
    });
    try {
        (new VerifiedReverbRedisClientFactory($config))->make($loop, 'rediss://'.$host.':'.$port.'?username=panel-user&password=private-test-password&db=1')->then(
            static function ($connected) use (&$client, $loop): void {
                $client = $connected;
                $loop->stop();
            },
            static function (Throwable $exception) use (&$failure, $loop): void {
                $failure = $exception;
                $loop->stop();
            },
        );
        $loop->run();
        if ($scenario === 'trusted') {
            expect($failure)->toBeNull()->and($client)->not->toBeNull()
                ->and($requests)->toHaveCount(2)
                ->and($requests[0])->toContain('auth', 'panel-user', 'private-test-password')
                ->and($requests[1])->toContain('select', "\r\n1\r\n");
        } else {
            expect($client)->toBeNull()->and($failure)->toBeInstanceOf(RuntimeException::class)
                ->and($failure->getMessage())->not->toContain('private-test-password', 'private-upstream-detail', 'Local TLS test did not finish.');
            if ($scenario !== 'authentication-failure') {
                expect($requests)->toBe([]);
            }
        }
    } finally {
        $loop->cancelTimer($timer);
        $client?->close();
        foreach ($connections as $connection) {
            $connection->close();
        }
        $server->close();
    }
})->with(['trusted', 'wrong-ca', 'wrong-name', 'authentication-failure']);

it('refuses plaintext endpoints before sending any Redis credentials when TLS is configured', function (): void {
    $config = new Repository(['reverb' => ['servers' => ['reverb' => ['scaling' => ['server' => ['scheme' => 'tls']]]]]]);
    $factory = new VerifiedReverbRedisClientFactory($config);
    expect(fn () => $factory->make(new StreamSelectLoop(), 'redis://localhost:6379?password=private-test-password'))->toThrow(RuntimeException::class);
});

it('rejects incomplete TLS trust and ACL configuration before connecting', function (mixed $ca, string $query, string $message): void {
    $config = new Repository([
        'reverb' => ['servers' => ['reverb' => ['scaling' => ['server' => ['scheme' => 'tls']]]]],
        'database' => ['redis' => ['default' => ['context' => ['ssl' => ['cafile' => $ca === 'fixture' ? $this->workspace.'/server.pem' : $ca]]]]],
    ]);

    expect(fn () => (new VerifiedReverbRedisClientFactory($config))->make(new StreamSelectLoop(), 'rediss://localhost:6379?'.$query))
        ->toThrow(RuntimeException::class, $message);
})->with([
    'missing trust' => [null, 'password=secret', 'readable trusted CA bundle'],
    'empty trust' => ['', 'password=secret', 'readable trusted CA bundle'],
    'non-string trust' => [[], 'password=secret', 'readable trusted CA bundle'],
    'unreadable trust' => ['/nonexistent/reverb-ca.pem', 'password=secret', 'readable trusted CA bundle'],
    'missing password' => ['fixture', '', 'Redis ACL credentials'],
    'empty password' => ['fixture', 'password=', 'Redis ACL credentials'],
    'array password' => ['fixture', 'password[]=secret', 'Redis ACL credentials'],
    'array username' => ['fixture', 'username[]=operator&password=secret', 'Redis ACL credentials'],
    'negative database' => ['fixture', 'password=secret&db=-1', 'database index is invalid'],
    'text database' => ['fixture', 'password=secret&db=primary', 'database index is invalid'],
    'array database' => ['fixture', 'password=secret&db[]=1', 'database index is invalid'],
]);

it('preserves the standard Redis connection when TLS scaling is disabled', function (): void {
    $loop = new StreamSelectLoop();
    $server = new TcpServer('127.0.0.1:0', $loop);
    $connections = [];
    $server->on('connection', static function (ConnectionInterface $connection) use (&$connections): void {
        $connections[] = $connection;
    });
    $client = null;
    $failure = null;
    $timer = $loop->addTimer(5, static function () use ($loop, &$failure): void {
        $failure = new RuntimeException('Local Redis connection did not finish.');
        $loop->stop();
    });

    try {
        (new VerifiedReverbRedisClientFactory(new Repository()))->make($loop, str_replace('tcp://', 'redis://', $server->getAddress()))->then(
            static function (Client $connected) use (&$client, $loop): void {
                $client = $connected;
                $loop->stop();
            },
            static function (Throwable $exception) use (&$failure, $loop): void {
                $failure = $exception;
                $loop->stop();
            },
        );
        $loop->run();
        expect($failure)->toBeNull()->and($client)->toBeInstanceOf(Client::class);
    } finally {
        $loop->cancelTimer($timer);
        $client?->close();
        foreach ($connections as $connection) {
            $connection->close();
        }
        $server->close();
    }
});
