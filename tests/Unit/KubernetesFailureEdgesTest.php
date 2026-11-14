<?php

use App\Hosting\Kubernetes\DataClusterPlan;
use App\Hosting\Kubernetes\KubernetesProvisioner;
use App\Hosting\Kubernetes\TenantWorkloadPlan;
use App\Hosting\Kubernetes\WorkloadPlanBuilder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

beforeEach(function (): void {
    $this->image = 'registry.example.test/app@sha256:'.str_repeat('a', 64);
    $this->settings = ['allowed_images' => [$this->image], 'runtime_class' => 'gvisor', 'network_policy_enforced' => true];
    $this->connection = ['url' => 'https://cluster.example.test', 'token' => 'private-fixture-token'];
    $this->remote = [];
    $this->readTransform = null;
    $this->writeTransform = null;
    $this->transportOptions = [];
    $this->http = new Factory();
    $this->http->preventStrayRequests();
    $this->http->fake(function (Request $request, array $options) {
        $this->transportOptions[] = $options;
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, '/runtimeclasses/')) {
            return Factory::response(['metadata' => ['name' => 'gvisor'], 'handler' => 'runsc']);
        }
        if ($request->method() === 'GET') {
            if (! isset($this->remote[$path])) {
                return Factory::response([], 404);
            }
            $body = $this->remote[$path];
            if ($this->readTransform !== null) {
                $body = ($this->readTransform)($body);
            }

            return Factory::response($body);
        }
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);
        if ($body['kind'] === 'Deployment') {
            $body['metadata']['generation'] = 1;
            $body['status'] = ['observedGeneration' => 1, 'updatedReplicas' => 1, 'readyReplicas' => 1, 'availableReplicas' => 1];
        }
        if ($this->writeTransform !== null) {
            $body = ($this->writeTransform)($body, str_contains($request->url(), 'dryRun=All'));
        }
        if ($request->method() === 'POST') {
            $path .= '/'.$body['metadata']['name'];
        }
        if (! str_contains($request->url(), 'dryRun=All')) {
            $this->remote[$path] = $body;
        }

        return Factory::response($body, $request->method() === 'POST' ? 201 : 200);
    });
    $this->provisioner = new KubernetesProvisioner($this->http, new TenantWorkloadPlan(), new DataClusterPlan());
});

it('rejects deployment drift after successful application', function (string $fault): void {
    $this->readTransform = static function (array $body) use ($fault): array {
        if ($body['kind'] === 'Deployment') {
            if ($fault === 'scalar pod') {
                $body['spec']['template']['spec'] = 'invalid';
            } else {
                $body['spec']['template']['spec']['containers'][] = ['name' => 'unapproved', 'image' => 'unapproved:latest'];
            }
        }

        return $body;
    };
    expect(fn () => $this->provisioner->deploy('team', 'workload', $this->image, $this->settings, $this->connection))
        ->toThrow(RuntimeException::class, 'The deployment no longer matches the restricted workload plan.');
})->with(['scalar pod', 'extra container']);

it('rejects weakened namespace admission before creating a workload', function (): void {
    $this->writeTransform = static function (array $body): array {
        if ($body['kind'] === 'Namespace') {
            $body['metadata']['labels']['pod-security.kubernetes.io/enforce'] = 'privileged';
        }

        return $body;
    };
    expect(fn () => $this->provisioner->deploy('team', 'workload', $this->image, $this->settings, $this->connection))
        ->toThrow(RuntimeException::class, 'Admission changed namespace security settings. No workload was created.');
    expect(collect($this->remote)->where('kind', 'Deployment'))->toBeEmpty();
});

it('rejects workload admission changes after isolation preflight', function (): void {
    $this->writeTransform = static function (array $body): array {
        if ($body['kind'] === 'Deployment') {
            $body['spec']['replicas'] = 2;
        }

        return $body;
    };
    expect(fn () => $this->provisioner->deploy('team', 'workload', $this->image, $this->settings, $this->connection))
        ->toThrow(RuntimeException::class, 'Admission changed a required security or resource setting. Inspect the namespace before retrying.');
});

it('accepts Kubernetes defaulting that omits disabled host namespace flags', function (): void {
    $this->writeTransform = static function (array $body): array {
        if ($body['kind'] === 'Deployment') {
            foreach (['hostNetwork', 'hostPID', 'hostIPC'] as $field) {
                unset($body['spec']['template']['spec'][$field]);
            }
        }

        return $body;
    };
    expect($this->provisioner->deploy('team', 'workload', $this->image, $this->settings, $this->connection)['ready'])->toBeTrue();
});

it('rejects unsafe Kubernetes connection options before contacting the API', function (array $override, string $message): void {
    expect(fn () => $this->provisioner->deploy('team', 'workload', $this->image, $this->settings, [...$this->connection, ...$override]))
        ->toThrow(InvalidArgumentException::class, $message);
    $this->http->assertNothingSent();
})->with([
    [['url' => 'http://cluster.example.test'], 'HTTPS Kubernetes endpoint'],
    [['token' => "secret\r\nInjected: value"], 'HTTPS Kubernetes endpoint'],
    [['ca_certificate' => '/nonexistent/kubernetes-ca.pem'], 'CA certificate is unreadable'],
    [['ca_certificate' => []], 'CA certificate is unreadable'],
]);

it('passes an explicit readable CA bundle to the HTTP transport', function (): void {
    $ca = tempnam(sys_get_temp_dir(), 'kubernetes-ca-');
    try {
        file_put_contents($ca, 'fixture-ca');
        expect($this->provisioner->deploy('team', 'workload', $this->image, $this->settings, [...$this->connection, 'ca_certificate' => $ca], true)['dry_run'])->toBeTrue();
        expect(array_unique(array_column($this->transportOptions, 'verify')))->toBe([$ca]);
    } finally {
        unlink($ca);
    }
});

it('rejects unsupported data drivers before contacting Kubernetes', function (): void {
    expect(fn () => $this->provisioner->deployDataCluster('team', 'database', 'sqlite', [], $this->connection))
        ->toThrow(InvalidArgumentException::class, 'Select a supported database cluster driver.');
    $this->http->assertNothingSent();
});

it('rejects a migration job changed between application and read-back', function (): void {
    $environment = tempnam(sys_get_temp_dir(), 'migration-env-');
    chmod($environment, 0600);
    file_put_contents($environment, "APP_ENV=production\n");
    $settings = [...$this->settings, 'storage_gi' => 2, 'storage_class' => 'durable', 'laravel' => [
        'environment_file' => $environment, 'app_key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'app_url' => 'https://site.example.test', 'migration_policy' => 'never',
        'database' => ['connection' => 'pgsql', 'host' => 'database.svc', 'database' => 'site', 'username' => 'site', 'password' => 'private-fixture-password'],
    ]];
    $this->readTransform = static function (array $body): array {
        if ($body['kind'] === 'Job') {
            $body['spec']['template']['spec']['restartPolicy'] = 'Always';
        }

        return $body;
    };
    try {
        expect(fn () => $this->provisioner->deploy('team', 'workload', $this->image, $settings, $this->connection))
            ->toThrow(RuntimeException::class, 'The migration job differs from its approved release. No deployment was updated.');
        expect(collect($this->remote)->where('kind', 'Deployment'))->toBeEmpty();
    } finally {
        unlink($environment);
    }
});

it('rejects incomplete or oversized generated plans before contacting Kubernetes', function (array $plan): void {
    $builder = Mockery::mock(WorkloadPlanBuilder::class);
    $builder->shouldReceive('build')->once()->with('team', 'workload', $this->image, $this->settings)->andReturn($plan);
    $provisioner = new KubernetesProvisioner($this->http, $builder, new DataClusterPlan());
    expect(fn () => $provisioner->deploy('team', 'workload', $this->image, $this->settings, $this->connection))
        ->toThrow(InvalidArgumentException::class, 'Use an intact operator-generated tenant workload plan.');
    $this->http->assertNothingSent();
})->with([
    'empty plan' => [[]],
    'oversized plan' => [array_fill(0, 17, ['path' => '/api/v1/namespaces/fixture', 'body' => ['kind' => 'Namespace']])],
    'missing namespace' => [array_fill(0, 7, ['path' => '/api/v1/services/fixture', 'body' => ['kind' => 'Service']])],
]);
