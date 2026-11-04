<?php

namespace Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/deploy/server/ll-readiness.php';

/** The private production gate must accept the strict policy and the approved temporary policy, and nothing else. */
class ReadinessGateTest extends TestCase
{
    /** @return array<string, mixed> */
    private function config(array $override = []): array
    {
        $origin = 'https://31.172.87.248:8080';

        return array_merge([
            'environment' => 'production', 'debug' => false, 'key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'connection' => 'pgsql', 'database' => 'linklibrary_prod', 'username' => 'linklibrary_app',
            'secure' => true, 'http_only' => true, 'same_site' => 'lax', 'session_driver' => 'database',
            'app_url' => $origin, 'frontend_url' => $origin, 'cors' => [$origin], 'stateful' => ['31.172.87.248:8080'],
            'cache' => 'database', 'queue' => 'database', 'retry_after' => 200,
            'allow_unverified' => false, 'scanning_coming_soon' => false,
        ], $override);
    }

    private function probe(): callable
    {
        return fn () => (object) ['db' => 'linklibrary_prod', 'usr' => 'linklibrary_app'];
    }

    /** @return array{ready: bool, checks: array<string, bool>} */
    private function evaluate(array $override = []): array
    {
        return llDeploymentReadiness($this->config($override), $this->probe());
    }

    public function test_strict_policy_is_ready(): void
    {
        $this->assertTrue($this->evaluate()['ready']);
        $this->assertTrue($this->evaluate(['scanning_coming_soon' => true])['ready']);
    }

    public function test_approved_temporary_policy_is_ready(): void
    {
        $result = $this->evaluate(['allow_unverified' => true, 'scanning_coming_soon' => true]);

        $this->assertTrue($result['ready']);
        $this->assertTrue($result['checks']['security_policy']);
    }

    public function test_unverified_sharing_without_the_coming_soon_state_is_refused(): void
    {
        $result = $this->evaluate(['allow_unverified' => true, 'scanning_coming_soon' => false]);

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['checks']['security_policy']);
    }

    public function test_non_boolean_or_missing_policy_values_are_refused(): void
    {
        foreach ([['allow_unverified' => 'true'], ['allow_unverified' => 1], ['scanning_coming_soon' => 'true', 'allow_unverified' => true]] as $bad) {
            $this->assertFalse($this->evaluate($bad)['checks']['security_policy'], json_encode($bad));
        }
        $config = $this->config();
        unset($config['scanning_coming_soon']);
        $this->assertFalse(llDeploymentReadiness($config, $this->probe())['checks']['security_policy']);
    }

    public function test_other_mandatory_checks_still_fail_closed(): void
    {
        $this->assertFalse($this->evaluate(['debug' => true])['ready']);
        $this->assertFalse($this->evaluate(['environment' => 'local'])['ready']);
        $this->assertFalse($this->evaluate(['username' => 'linklibrary_migrator'])['ready']);
        $this->assertFalse(llDeploymentReadiness($this->config(), fn () => throw new \RuntimeException('secret'))['ready']);
    }
}
