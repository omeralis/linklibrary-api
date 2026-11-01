<?php

namespace Database\Factories;

use App\Enums\AiStatus;
use App\Enums\ExtractionStatus;
use App\Enums\SecurityStatus;
use App\Enums\SourceType;
use App\Models\Link;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Link> */
class LinkFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $url = 'https://example-'.Str::lower(Str::random(8)).'.com/articles/'.Str::lower(Str::random(6));

        return [
            'url_hash' => hash('sha256', $url),
            'normalized_url' => $url,
            'original_url' => $url,
            'hostname' => (string) parse_url($url, PHP_URL_HOST),
            'source_type' => SourceType::ARTICLE,
            'title' => fake()->sentence(5),
            'description' => fake()->paragraph(),
            'image_url' => 'https://images.example.com/'.Str::random(8).'.jpg',
            'site_name' => fake()->company(),
            'language' => 'en',
            'extraction_status' => ExtractionStatus::COMPLETED,
            'last_extracted_at' => now(),
            'security_status' => SecurityStatus::CLEAR,
            'security_checked_at' => now(),
            'security_expires_at' => now()->addDays(7),
            'ai_status' => AiStatus::DISABLED,
            'extraction_version' => 1,
            'ai_version' => 1,
            'security_source' => 'PROVIDER',
        ];
    }

    public function security(SecurityStatus $status): static
    {
        return $this->state(fn () => [
            'security_status' => $status,
            'security_expires_at' => $status === SecurityStatus::CLEAR ? now()->addDays(7) : null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'title' => null,
            'description' => null,
            'image_url' => null,
            'extraction_status' => ExtractionStatus::PENDING,
            'security_status' => SecurityStatus::PENDING,
            'security_expires_at' => null,
            'ai_status' => AiStatus::PENDING,
        ]);
    }
}
