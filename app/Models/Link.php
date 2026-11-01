<?php

namespace App\Models;

use App\Enums\AiStatus;
use App\Enums\ExtractionStatus;
use App\Enums\SecuritySource;
use App\Enums\SecurityStatus;
use App\Enums\SourceType;
use Database\Factories\LinkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Canonical source-level record shared by every user who saved the same normalized URL.
 *
 * @property int $id
 * @property string $url_hash
 * @property string $normalized_url
 * @property string $original_url
 * @property string $hostname
 * @property SourceType $source_type
 * @property string|null $title
 * @property string|null $description
 * @property string|null $image_url
 * @property string|null $favicon_url
 * @property string|null $canonical_url
 * @property string|null $site_name
 * @property string|null $language
 * @property string|null $author
 * @property Carbon|null $published_at
 * @property string|null $content_type
 * @property string|null $text_excerpt
 * @property array<string, mixed>|null $metadata
 * @property ExtractionStatus $extraction_status
 * @property string|null $extraction_error_code
 * @property int $extraction_version
 * @property Carbon|null $last_extracted_at
 * @property SecurityStatus $security_status
 * @property SecuritySource $security_source
 * @property Carbon|null $security_checked_at
 * @property Carbon|null $security_expires_at
 * @property int|null $last_security_scan_id
 * @property AiStatus $ai_status
 * @property int $ai_version
 * @property int|null $current_ai_analysis_id
 * @property-read LinkAiAnalysis|null $currentAiAnalysis
 */
class Link extends Model
{
    /** @use HasFactory<LinkFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_type' => SourceType::class,
            'extraction_status' => ExtractionStatus::class,
            'security_status' => SecurityStatus::class,
            'security_source' => SecuritySource::class,
            'ai_status' => AiStatus::class,
            'metadata' => 'array',
            'published_at' => 'datetime',
            'last_extracted_at' => 'datetime',
            'security_checked_at' => 'datetime',
            'security_expires_at' => 'datetime',
        ];
    }

    /**
     * Security status as it applies right now: a CLEAR verdict past its expiry no longer counts as CLEAR.
     */
    public function effectiveSecurityStatus(): SecurityStatus
    {
        if ($this->security_status === SecurityStatus::CLEAR
            && ($this->security_expires_at === null || $this->security_expires_at->isPast())) {
            return SecurityStatus::UNKNOWN;
        }

        return $this->security_status;
    }

    public function isClear(): bool
    {
        return $this->effectiveSecurityStatus() === SecurityStatus::CLEAR;
    }

    /** @return HasMany<SavedLink, $this> */
    public function savedLinks(): HasMany
    {
        return $this->hasMany(SavedLink::class);
    }

    /** @return HasMany<LinkSecurityScan, $this> */
    public function securityScans(): HasMany
    {
        return $this->hasMany(LinkSecurityScan::class);
    }

    /** @return HasMany<LinkExtractionJob, $this> */
    public function extractionJobs(): HasMany
    {
        return $this->hasMany(LinkExtractionJob::class);
    }

    /** @return HasMany<LinkAiAnalysis, $this> */
    public function aiAnalyses(): HasMany
    {
        return $this->hasMany(LinkAiAnalysis::class);
    }

    /** @return BelongsTo<LinkAiAnalysis, $this> */
    public function currentAiAnalysis(): BelongsTo
    {
        return $this->belongsTo(LinkAiAnalysis::class, 'current_ai_analysis_id');
    }
}
