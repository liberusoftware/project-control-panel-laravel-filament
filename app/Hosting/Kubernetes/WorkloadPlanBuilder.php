<?php

namespace App\Hosting\Kubernetes;

interface WorkloadPlanBuilder
{
    /**
     * @param  array<string, mixed>  $settings
     * @return list<array{path: string, body: array<string, mixed>}>
     */
    public function build(string $teamId, string $workloadId, string $image, array $settings): array;
}
