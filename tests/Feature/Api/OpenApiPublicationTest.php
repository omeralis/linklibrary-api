<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class OpenApiPublicationTest extends TestCase
{
    public function test_public_specification_is_served_without_internal_documentation(): void
    {
        $response = $this->get('/docs/openapi.yaml');
        $response->assertOk()->assertHeader('Content-Type', 'application/yaml');
        $this->assertSame(realpath(base_path('docs/openapi.yaml')), $response->baseResponse->getFile()->getRealPath());
        $this->get('/docs/CICD.md')->assertNotFound();
    }
}
