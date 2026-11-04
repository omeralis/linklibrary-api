<?php

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class WorkflowGovernanceTest extends TestCase
{
    public function test_production_deploys_only_from_main_behind_the_switch_with_exact_sha_and_environment(): void
    {
        $workflow = Yaml::parseFile(__DIR__.'/../../../.github/workflows/deploy.yml');
        $this->assertEqualsCanonicalizing(['push', 'workflow_dispatch'], array_keys($workflow['on']));
        $this->assertSame(['main'], $workflow['on']['push']['branches']);
        $this->assertSame(['contents' => 'read'], $workflow['permissions']);
        $this->assertFalse($workflow['concurrency']['cancel-in-progress']);
        $this->assertStringContainsString('DEPLOY_ENABLED', $workflow['jobs']['authorize']['if']);
        $this->assertStringContainsString("github.ref == 'refs/heads/main'", $workflow['jobs']['authorize']['if']);
        $this->assertStringContainsString("github.event_name == 'push'", $workflow['jobs']['authorize']['if']);
        $this->assertStringContainsString('inputs.authorization', $workflow['jobs']['authorize']['if']);
        foreach (['deploy', 'rollback'] as $job) {
            $this->assertSame('production', $workflow['jobs'][$job]['environment']);
            $this->assertContains('authorize', (array) $workflow['jobs'][$job]['needs']);
            $cleanup = array_filter($workflow['jobs'][$job]['steps'], fn (array $step) => ($step['if'] ?? '') === 'always()');
            $this->assertNotEmpty($cleanup);
        }
        $this->assertContains('test', $workflow['jobs']['deploy']['needs']);
        $this->assertContains('deployment-security', $workflow['jobs']['deploy']['needs']);
        $this->assertContains('build', $workflow['jobs']['deploy']['needs']);
        $this->assertArrayNotHasKey('environment', $workflow['jobs']['build']);
        $this->assertStringNotContainsString('secrets.', json_encode($workflow['jobs']['build']));
        foreach ($workflow['jobs']['deploy']['steps'] as $step) {
            $this->assertDoesNotMatchRegularExpression('/npm |composer |build-api/', $step['run'] ?? '');
        }
    }

    public function test_build_is_independent_and_actions_match_verified_pins(): void
    {
        $base = __DIR__.'/../../../';
        $ci = Yaml::parseFile($base.'.github/workflows/ci.yml');
        $this->assertArrayHasKey('push', $ci['on']);
        $this->assertArrayHasKey('build', $ci['jobs']);
        $this->assertArrayNotHasKey('if', $ci['jobs']['build']);
        $pins = json_decode(file_get_contents($base.'deploy/ACTION_PINS.json'), true)['actions'];
        foreach (glob($base.'.github/workflows/*.yml') as $path) {
            $workflow = Yaml::parseFile($path);
            foreach ($workflow['jobs'] as $job) {
                foreach ($job['steps'] ?? [] as $step) {
                    if (isset($step['uses'])) {
                        [$action, $sha] = explode('@', $step['uses']);
                        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/D', $sha);
                        $this->assertSame($pins[$action]['sha'], $sha);
                    }
                }
            }
        }
    }
}
