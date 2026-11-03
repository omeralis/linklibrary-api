<?php

namespace App\Http\Resources;

use App\Enums\AiStatus;
use App\Enums\ExtractionStatus;
use App\Enums\ProcessingStatus;
use App\Enums\SecurityStatus;
use App\Models\Link;
use App\Models\SavedLink;
use App\Services\Ai\AiQuotaGuard;
use App\Services\Ai\AiService;
use App\Services\Security\SecurityPolicy;
use App\Services\Sharing\LinkViewContext;
use App\Support\FrontendUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Context-aware representation of a saved link.
 *  - owner:  everything (notes, overrides, raw extracted data, processing, sharing settings)
 *  - viewer: read-only metadata permitted for invited viewers (no notes, no owner settings)
 *  - public: public metadata only
 *
 * @mixin SavedLink
 */
class SavedLinkResource extends JsonResource
{
    public string $role = LinkViewContext::OWNER;

    /** @var array<string, bool> */
    public array $permissions = [];

    public ?string $sharedByName = null;

    public static function forRole(SavedLink $savedLink, string $role): self
    {
        $resource = new self($savedLink);
        $resource->role = $role;

        return $resource;
    }

    public static function fromContext(LinkViewContext $context, bool $canReshare = false): self
    {
        $resource = self::forRole($context->savedLink, $context->role);
        $resource->permissions = ['can_reshare' => $canReshare];
        if ($context->grant !== null) {
            $context->grant->loadMissing('grantor');
            $resource->sharedByName = $context->grant->grantor->name;
        }

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SavedLink $s */
        $s = $this->resource;
        $link = $s->link;
        $policy = app(SecurityPolicy::class);
        $isOwner = $this->role === LinkViewContext::OWNER;
        $status = $link->effectiveSecurityStatus();
        $canOpen = $policy->mayOpen($link, $isOwner);
        $analysis = $link->currentAiAnalysis;

        $data = [
            'public_id' => $s->public_id,
            'internal_path' => '/l/'.$s->public_id,
            'frontend_url' => FrontendUrl::link($s->public_id),
            'access_role' => $this->role,
            'title' => $s->displayTitle(),
            'description' => $s->displayDescription(),
            'image_url' => $s->displayImageUrl(),
            'favicon_url' => $link->favicon_url,
            'site_name' => $link->site_name,
            'hostname' => $link->hostname,
            'source_type' => $s->displaySourceType()->value,
            'language' => $link->language ?? $analysis?->language,
            'author' => $link->author,
            'source_published_at' => $link->published_at?->toIso8601String(),
            'category' => CategoryResource::brief($s->displayCategory()),
            'tags' => $s->tags->map(fn ($t) => [
                'name' => $t->name,
                'slug' => $t->slug,
                'source' => $isOwner ? $t->pivot->getAttribute('source') : null,
            ])->values()->all(),
            'ai' => $analysis !== null && $analysis->status === 'COMPLETED' ? [
                'summary' => $analysis->short_summary,
                'detailed_summary' => $analysis->detailed_summary,
                'suggested_title' => $analysis->suggested_title,
                'suggested_category' => CategoryResource::brief($s->aiCategory),
                'suggested_tags' => $analysis->suggested_tags ?? [],
                'generated' => true,
            ] : null,
            // Original URL exposed only when the platform may open it for this viewer.
            'url' => $canOpen || $isOwner ? $link->original_url : null,
            'open_url' => $canOpen ? $link->normalized_url : null,
            'security' => [
                'status' => $status->value,
                'can_open' => $canOpen,
                'warning' => $policy->requiresWarning($link) ? 'security.'.strtolower($status->value) : null,
                'checked_at' => $link->security_checked_at?->toIso8601String(),
            ],
            'owner' => PublicUserResource::summary($s->user),
            'published_at' => $s->isPublic() ? $s->published_at?->toIso8601String() : null,
        ];

        if ($this->role === LinkViewContext::VIEWER) {
            $data['shared_by'] = $this->sharedByName;
            $data['permissions'] = [
                'can_edit' => false,
                'can_delete' => false,
                'can_change_visibility' => false,
                'can_reshare' => $this->permissions['can_reshare'] ?? false,
            ];
        }

        if ($isOwner) {
            $data += [
                'visibility' => $s->visibility->value,
                'allow_resharing' => $s->allow_resharing,
                'is_favorite' => $s->is_favorite,
                'notes' => $s->notes,
                'overrides' => [
                    'title' => $s->custom_title,
                    'description' => $s->custom_description,
                    'image_url' => $s->customImagePublicUrl() ?? $s->custom_image_url,
                    'source_type' => $s->custom_source_type?->value,
                    'category' => CategoryResource::brief($s->category),
                ],
                'extracted' => [
                    'title' => $link->title,
                    'description' => $link->description,
                    'image_url' => $link->image_url,
                    'canonical_url' => $link->canonical_url,
                    'content_type' => $link->content_type,
                    'source_type' => $link->source_type->value,
                    'status' => $link->extraction_status->value,
                    'error_code' => $link->extraction_error_code,
                    'extracted_at' => $link->last_extracted_at?->toIso8601String(),
                ],
                'processing_status' => self::processingStatus($link)->value,
                'processing' => self::processing($link),
                'permissions' => [
                    'can_edit' => true,
                    'can_delete' => true,
                    'can_change_visibility' => true,
                    'can_publish' => $policy->mayPublish($link),
                    'can_share' => $policy->mayShare($link),
                    'can_reshare' => true,
                ],
                'created_at' => $s->created_at->toIso8601String(),
                'updated_at' => $s->updated_at->toIso8601String(),
                'deleted_at' => $s->deleted_at?->toIso8601String(),
            ];
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function processing(Link $link): array
    {
        $wait = self::aiQuotaWait($link);

        return [
            'status' => self::processingStatus($link)->value,
            'security' => $link->effectiveSecurityStatus()->value,
            'extraction' => $link->extraction_status->value,
            'extraction_error_code' => $link->extraction_error_code,
            'ai' => $link->ai_status->value,
            'ai_enabled' => app(AiService::class)->isEnabled(),
            // Free AI quota exhausted: AI suggestions are queued and resume automatically at `retry_at`.
            'ai_wait' => $wait === null ? null : ['code' => 'AI_QUOTA_EXHAUSTED', 'retry_at' => $wait->toIso8601String()],
        ];
    }

    private static function aiQuotaWait(Link $link): ?Carbon
    {
        if ($link->ai_status !== AiStatus::PENDING || ! app(AiService::class)->isEnabled()) {
            return null;
        }

        return app(AiQuotaGuard::class)->unavailableUntil();
    }

    /** Aggregate state derived from the security, extraction and AI pipelines. */
    public static function processingStatus(Link $link): ProcessingStatus
    {
        $security = $link->security_status;
        if ($security === SecurityStatus::PENDING) {
            return ProcessingStatus::PENDING;
        }
        if ($security->isUnsafe()) {
            return ProcessingStatus::BLOCKED;
        }

        return match ($link->extraction_status) {
            ExtractionStatus::PENDING => ProcessingStatus::PENDING,
            ExtractionStatus::PROCESSING => ProcessingStatus::PROCESSING,
            ExtractionStatus::FAILED => ProcessingStatus::FAILED,
            ExtractionStatus::BLOCKED => ProcessingStatus::BLOCKED,
            ExtractionStatus::PARTIAL, ExtractionStatus::COMPLETED => in_array($link->ai_status, [AiStatus::PENDING, AiStatus::PROCESSING], true) && app(AiService::class)->isEnabled() && self::aiQuotaWait($link) === null
                ? ProcessingStatus::PROCESSING
                : ($link->extraction_status === ExtractionStatus::COMPLETED ? ProcessingStatus::COMPLETED : ProcessingStatus::PARTIAL),
        };
    }
}
