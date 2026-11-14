<?php

use App\Console\Commands\ConfigureMailCluster;
use App\Hosting\Mail\StalwartCluster;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Liberu\ControlPanel\ControlCore\Actions\WriteAuditEntry;
use Liberu\ControlPanel\ControlCore\ControlCoreServiceProvider;
use Liberu\ControlPanel\ControlCore\Models\AuditEntry;
use Liberu\ControlPanel\Mail\MailServiceProvider;
use Liberu\ControlPanel\Mail\Models\MailDomain;

beforeEach(function (): void {
    app()->register(MailServiceProvider::class);
    app()->register(ControlCoreServiceProvider::class);
    $this->artisan('migrate');
    $this->domain = MailDomain::query()->create(['team_id' => 'team-a', 'domain' => 'example.test', 'status' => 'active']);
    $this->nodes = ['mail1' => ['url' => 'https://mail1.example.test', 'token' => 'private-mail1-token'], 'mail2' => ['url' => 'https://mail2.example.test', 'token' => 'private-mail2-token']];
    $this->dataStore = ['@type' => 'PostgreSql', 'host' => 'postgres.example.test', 'port' => 5432, 'database' => 'mail', 'authUsername' => 'mail', 'useTls' => true, 'allowInvalidCerts' => false];
    $this->memoryStore = ['@type' => 'RedisCluster', 'urls' => ['rediss://cache1.example.test:6379' => true, 'rediss://cache2.example.test:6379' => true], 'allowInvalidCerts' => false, 'authUsername' => 'default', 'authSecret' => ['@type' => 'EnvironmentVariable', 'variableName' => 'STALWART_REDIS_PASSWORD']];
    $this->shared = ['DataStore' => $this->dataStore, 'BlobStore' => ['@type' => 'Default'], 'SearchStore' => ['@type' => 'Default'], 'InMemoryStore' => ['@type' => 'Default'], 'Coordinator' => ['@type' => 'Disabled']];
    $this->offline = false;
    $this->divergentStore = false;
    $this->stateMismatch = false;
    $this->unhealthy = false;
    $this->version = 1;
    $this->responseFault = null;
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        $second = str_contains($request->url(), 'mail2.');
        if ($second && $this->offline) {
            return Http::response(['error' => 'private-password'], 503);
        }
        if (str_ends_with($request->url(), '/healthz/ready')) {
            return Http::response('', $second && $this->unhealthy ? 503 : 200);
        }
        $replies = [];
        foreach ($request['methodCalls'] as [$method, $arguments, $id]) {
            preg_match('~^x:(.+)/(get|set)$~', $method, $matches);
            $object = $matches[1];
            if ($matches[2] === 'get') {
                $value = $this->shared[$object];
                if ($second && $this->divergentStore && $object === 'DataStore') {
                    $value['database'] = 'foreign-mailstore';
                }
                if ($second && $this->version > 1 && $object === 'InMemoryStore') {
                    if ($this->responseFault === 'memory-type') {
                        $value['@type'] = 'Default';
                    } elseif ($this->responseFault === 'memory-seeds') {
                        $value['urls'] = ['rediss://foreign.example.test:6379' => true];
                    }
                }
                $replies[] = [$method, ['state' => (string) $this->version, 'list' => [['id' => 'singleton', ...$value]], 'notFound' => []], $id];
            } elseif ($object === 'Action') {
                if ($second && $this->responseFault === 'reload') {
                    $replies[] = ['error', ['type' => 'serverFail', 'description' => 'private reload detail'], $id];
                } else {
                    $replies[] = [$method, ['created' => ['reload' => ['id' => 'reload-action']]], $id];
                }
            } elseif ($this->stateMismatch) {
                $replies[] = ['error', ['type' => 'stateMismatch', 'description' => 'private upstream data'], $id];
            } else {
                $this->shared[$object] = $arguments['update']['singleton'];
                $this->version++;
                $replies[] = [$method, ['updated' => ['singleton' => null]], $id];
            }
        }

        if ($second && str_ends_with($request['methodCalls'][0][0], '/get')) {
            if ($this->responseFault === 'incomplete') {
                array_pop($replies);
            } elseif ($this->responseFault === 'malformed') {
                $replies[0][1]['list'] = [];
            } elseif ($this->responseFault === 'duplicate') {
                $replies[1] = $replies[0];
            }
        }

        return Http::response(['methodResponses' => $replies]);
    });
    Cache::swap(new Repository(new ArrayStore()));
    config(['cache.default' => 'redis', 'hosting-mail.sources.fixture' => ['team_id' => 'team-a', 'domain_id' => $this->domain->getKey(), 'domain' => 'example.test', 'nodes' => $this->nodes, 'data_store' => $this->dataStore, 'memory_store' => $this->memoryStore]]);
});

it('configures one shared memory store then coordination and verifies every mail node', function (): void {
    $result = app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore);
    expect($result['verified'])->toBeTrue()
        ->and($this->shared['Coordinator'])->toBe(['@type' => 'Default'])
        ->and($this->shared['InMemoryStore'])->toBe($this->memoryStore)
        ->and(json_encode($result))->not->toContain('private-mail', 'STALWART_REDIS_PASSWORD');
    Http::assertNotSent(fn (Request $request): bool => collect($request['methodCalls'] ?? [])->contains(fn (array $call): bool => in_array($call[0], ['x:DataStore/set', 'x:BlobStore/set', 'x:SearchStore/set'], true)));
});

it('does not configure independent local or mismatched mail stores as a cluster', function (string $failure): void {
    match ($failure) {
        'offline' => $this->offline = true,
        'different-database' => $this->divergentStore = true,
        'filesystem-blobs' => $this->shared['BlobStore'] = ['@type' => 'Fs', 'path' => '/mail'],
    };
    expect(app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore)['verified'])->toBeFalse();
    Http::assertNotSent(fn (Request $request): bool => collect($request['methodCalls'] ?? [])->contains(fn (array $call): bool => str_ends_with($call[0], '/set')));
})->with(['offline', 'different-database', 'filesystem-blobs']);

it('preflights all nodes without changing mail configuration during a dry run', function (): void {
    $before = $this->shared;
    $result = app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore, true);
    expect($result['dry_run'])->toBeTrue()->and($this->shared)->toBe($before);
    Http::assertSentCount(2);
});

it('does not accept a JMAP HTTP success containing a state conflict as a successful update', function (): void {
    $this->stateMismatch = true;
    $result = app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore);
    expect($result['verified'])->toBeFalse()->and(json_encode($result))->not->toContain('private upstream data');
});

it('requires node health as well as shared configuration before reporting verification', function (): void {
    $this->unhealthy = true;
    $result = app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore);
    expect($result['verified'])->toBeFalse()->and($result['nodes']['mail2']['status'])->toBe('verification-failed');
});

it('rejects embedded datastores or plaintext in-memory cluster connections', function (string $failure): void {
    if ($failure === 'embedded') {
        $this->dataStore['@type'] = 'RocksDb';
    } else {
        $this->memoryStore['urls'] = ['redis://cache1.example.test' => true, 'redis://cache2.example.test' => true];
    }
    expect(fn () => app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['embedded', 'plaintext']);

it('writes sanitized team-scoped audit evidence through the mail cluster command', function (): void {
    $this->artisan('hosting:configure-mail-cluster fixture')->assertSuccessful();
    $entry = AuditEntry::query()->where('event', 'hosting.mail-cluster.configured')->firstOrFail();
    expect($entry->team_id)->toBe('team-a')->and($entry->context['verified'])->toBeTrue()
        ->and(json_encode($entry))->not->toContain('private-mail', 'STALWART_REDIS_PASSWORD');
});

it('refuses a foreign team source before contacting mail infrastructure', function (): void {
    config(['hosting-mail.sources.fixture.team_id' => 'team-b']);
    $this->artisan('hosting:configure-mail-cluster fixture')->assertFailed();
    Http::assertNothingSent();
});

it('rejects unsafe memory-store or mail-node configuration before sending credentials', function (string $fault): void {
    match ($fault) {
        'missing secret' => $this->memoryStore['authSecret'] = [],
        'single Redis seed' => array_pop($this->memoryStore['urls']),
        'invalid secret name' => $this->memoryStore['authSecret']['variableName'] = 'unsafe-name',
        'plaintext node' => $this->nodes['mail1']['url'] = 'http://mail1.example.test',
        'duplicate nodes' => $this->nodes['mail2']['url'] = $this->nodes['mail1']['url'],
        'header injection' => $this->nodes['mail1']['token'] = "token\r\nInjected: value",
    };

    expect(fn () => app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['missing secret', 'single Redis seed', 'invalid secret name', 'plaintext node', 'duplicate nodes', 'header injection']);

it('rejects incomplete or ambiguous JMAP configuration without changing any node', function (string $fault): void {
    $this->responseFault = $fault;
    $result = app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore);

    expect($result['verified'])->toBeFalse()
        ->and($result['nodes']['mail2']['status'])->toBe('preflight-failed');
    Http::assertNotSent(fn (Request $request): bool => collect($request['methodCalls'] ?? [])->contains(fn (array $call): bool => str_ends_with($call[0], '/set')));
})->with(['incomplete', 'malformed', 'duplicate']);

it('requires successful reload and exact memory-store read-back on every node', function (string $fault): void {
    $this->responseFault = $fault;
    $result = app(StalwartCluster::class)->configure($this->nodes, $this->dataStore, $this->memoryStore);

    expect($result['verified'])->toBeFalse()
        ->and($result['nodes']['mail1']['status'])->toBe('configuration-verified')
        ->and($result['nodes']['mail2']['status'])->toBe('verification-failed')
        ->and(json_encode($result))->not->toContain('private reload detail', 'private-mail');
})->with(['reload', 'memory-type', 'memory-seeds']);

it('requires complete operator source configuration before making mail requests', function (): void {
    config(['hosting-mail.sources.fixture.memory_store' => null]);
    $this->artisan('hosting:configure-mail-cluster fixture')->assertFailed()
        ->expectsOutput('Configure a team-owned mail domain, allowlisted nodes, shared datastore and in-memory store.');
    Http::assertNothingSent();
});

it('runs command preflight without writing mail settings', function (): void {
    $this->artisan('hosting:configure-mail-cluster fixture --dry-run')->assertSuccessful()
        ->expectsOutput('Shared-store preflight passed. No server settings were changed.');
    expect(AuditEntry::query()->where('event', 'hosting.mail-cluster.configured')->firstOrFail()->context['dry_run'])->toBeTrue();
    Http::assertSentCount(2);
});

it('preserves a competing operator lock and never contacts mail nodes', function (): void {
    $key = hash('sha256', json_encode([$this->dataStore['host'], $this->dataStore['port'], $this->dataStore['database']], JSON_THROW_ON_ERROR));
    $lock = Cache::lock('hosting:mail-cluster:'.$key, 600);
    expect($lock->get())->toBeTrue();
    try {
        $this->artisan('hosting:configure-mail-cluster fixture')->assertFailed()
            ->expectsOutput('This mail cluster is already being configured.');
        expect($lock->isOwnedByCurrentProcess())->toBeTrue();
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

it('reports failed cluster verification through the command and records the evidence', function (): void {
    $this->unhealthy = true;
    $this->artisan('hosting:configure-mail-cluster fixture')->assertFailed()
        ->expectsOutput('Cluster-wide mail configuration was not verified. Review node results and reconcile before serving mail.');
    expect(AuditEntry::query()->where('event', 'hosting.mail-cluster.configured')->firstOrFail()->context['verified'])->toBeFalse();
});

it('explains invalid mail configuration and releases its lock for a corrected retry', function (): void {
    config(['hosting-mail.sources.fixture.memory_store.authSecret' => []]);
    $this->artisan('hosting:configure-mail-cluster fixture')->assertFailed()
        ->expectsOutput('Use a TLS Redis/Valkey Cluster in-memory store with at least two seeds and a node-local environment secret.');
    Http::assertNothingSent();
    config(['hosting-mail.sources.fixture.memory_store' => $this->memoryStore]);
    $this->artisan('hosting:configure-mail-cluster fixture')->assertSuccessful();
});

it('sanitizes unexpected infrastructure failures before displaying them to the operator', function (): void {
    Cache::shouldReceive('lock')->once()->andThrow(new RuntimeException('private Redis credentials'));
    $this->artisan('hosting:configure-mail-cluster fixture')->assertFailed()
        ->expectsOutput('Mail cluster configuration failed. Check API permissions, shared storage and node connectivity. Datastore configuration and mailboxes were not migrated.');
    Http::assertNothingSent();
});

it('requires the owning modules before configuring mail infrastructure', function (): void {
    $original = Container::getInstance();
    $mail = app(StalwartCluster::class);
    $audit = app(WriteAuditEntry::class);
    $command = Mockery::mock(ConfigureMailCluster::class)->makePartial();
    $command->shouldReceive('error')->once()->with('Enable mail and control-core modules before configuring mail clusters.');

    try {
        Container::setInstance(new Application(dirname(__DIR__, 2)));
        expect($command->handle($mail, $audit))->toBe(1);
    } finally {
        Container::setInstance($original);
    }
    Http::assertNothingSent();
});
