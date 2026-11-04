<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../../deploy/server/ll-readiness.php';

class DeploymentReadinessTest extends TestCase
{
    private function goodConfig(): array
    {
        return [
            'environment' => 'production', 'debug' => false, 'key' => 'base64:'.base64_encode(str_repeat('x', 32)),
            'connection' => 'pgsql', 'database' => 'linklibrary_prod', 'username' => 'linklibrary_app',
            'secure' => true, 'http_only' => true, 'same_site' => 'lax', 'session_driver' => 'database',
            'app_url' => 'https://example.invalid', 'frontend_url' => 'https://example.invalid',
            'cors' => ['https://example.invalid'], 'stateful' => ['example.invalid'],
            'cache' => 'database', 'queue' => 'database', 'retry_after' => 180, 'allow_unverified' => false, 'scanning_coming_soon' => false,
        ];
    }

    public function test_all_mandatory_configuration_gates_accept_secure_production(): void
    {
        $result = llDeploymentReadiness($this->goodConfig(), fn () => (object) ['db' => 'linklibrary_prod', 'usr' => 'linklibrary_app']);
        $this->assertTrue($result['ready']);
        $this->assertNotContains(false, $result['checks']);
    }

    public function test_each_unsafe_configuration_is_rejected(): void
    {
        foreach ([
            'environment' => 'local', 'debug' => true, 'key' => 'invalid', 'connection' => 'sqlite',
            'database' => 'wrong', 'username' => 'postgres', 'secure' => false, 'http_only' => false,
            'same_site' => 'none', 'session_driver' => 'array', 'app_url' => 'http://example.invalid',
            'frontend_url' => 'https://other.invalid', 'cors' => [], 'stateful' => [], 'cache' => 'array',
            'queue' => 'sync', 'retry_after' => 90, 'allow_unverified' => true,
        ] as $key => $value) {
            $result = llDeploymentReadiness(array_replace($this->goodConfig(), [$key => $value]), fn () => (object) ['db' => 'linklibrary_prod', 'usr' => 'linklibrary_app']);
            $this->assertFalse($result['ready'], 'unsafe configuration accepted: '.$key);
        }
    }

    public function test_wrong_database_and_role_are_rejected(): void
    {
        foreach ([['db' => 'wrong', 'usr' => 'linklibrary_app'], ['db' => 'linklibrary_prod', 'usr' => 'postgres']] as $row) {
            $result = llDeploymentReadiness($this->goodConfig(), fn () => (object) $row);
            $this->assertFalse($result['ready']);
            $this->assertFalse($result['checks']['database_runtime']);
        }
    }

    public function test_extra_cors_or_stateful_origins_are_rejected(): void
    {
        foreach (['cors' => ['https://example.invalid', '*'], 'stateful' => ['example.invalid', 'localhost']] as $key => $value) {
            $result = llDeploymentReadiness(array_replace($this->goodConfig(), [$key => $value]), fn () => (object) ['db' => 'linklibrary_prod', 'usr' => 'linklibrary_app']);
            $this->assertFalse($result['ready']);
        }
    }

    public function test_database_failure_never_exposes_exception_or_configuration(): void
    {
        $result = llDeploymentReadiness($this->goodConfig(), function () {
            throw new RuntimeException('PRIVATE-CREDENTIAL-SENTINEL');
        });
        $this->assertFalse($result['ready']);
        $this->assertStringNotContainsString('PRIVATE-CREDENTIAL-SENTINEL', json_encode($result));
        $this->assertStringNotContainsString('base64:', json_encode($result));
    }
}
