<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * links: canonical, source-level information shared across all users that saved the same
         * normalized URL. Contains no user-private data.
         */
        Schema::create('links', function (Blueprint $table) {
            $table->id();
            $table->char('url_hash', 64)->unique();
            $table->text('normalized_url');
            // First submitted form (fragment included). Never contains credentials (rejected on input).
            $table->text('original_url');
            $table->string('hostname', 253);
            $table->string('source_type', 20)->default('WEBSITE');

            // Extracted metadata (never AI-generated, never user-supplied).
            $table->text('title')->nullable();
            $table->text('description')->nullable();
            $table->text('image_url')->nullable();
            $table->text('favicon_url')->nullable();
            $table->text('canonical_url')->nullable();
            $table->string('site_name', 255)->nullable();
            $table->string('language', 12)->nullable();
            $table->string('author', 255)->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->string('content_type', 120)->nullable();
            $table->text('text_excerpt')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->string('extraction_status', 20)->default('PENDING');
            $table->string('extraction_error_code', 64)->nullable();
            $table->unsignedInteger('extraction_version')->default(1);
            $table->timestampTz('last_extracted_at')->nullable();

            $table->string('security_status', 20)->default('PENDING');
            $table->string('security_source', 20)->default('PROVIDER');
            $table->timestampTz('security_checked_at')->nullable();
            $table->timestampTz('security_expires_at')->nullable();
            $table->unsignedBigInteger('last_security_scan_id')->nullable();

            $table->string('ai_status', 20)->default('PENDING');
            $table->unsignedInteger('ai_version')->default(1);
            $table->unsignedBigInteger('current_ai_analysis_id')->nullable();

            $table->timestampsTz();

            $table->index('hostname');
            $table->index(['security_status', 'security_expires_at']);
            $table->index(['extraction_status', 'last_extracted_at']);
        });

        foreach ([
            'source_type' => ['WEBSITE', 'ARTICLE', 'VIDEO', 'PLAYLIST', 'PDF', 'REPOSITORY', 'IMAGE', 'AUDIO', 'DOCUMENT', 'OTHER'],
            'extraction_status' => ['PENDING', 'PROCESSING', 'COMPLETED', 'PARTIAL', 'FAILED', 'BLOCKED'],
            'security_status' => ['PENDING', 'CLEAR', 'SUSPICIOUS', 'MALICIOUS', 'UNKNOWN'],
            'security_source' => ['PROVIDER', 'MANUAL_REVIEW'],
            'ai_status' => ['PENDING', 'PROCESSING', 'COMPLETED', 'FAILED', 'SKIPPED', 'DISABLED'],
        ] as $column => $values) {
            $list = "'".implode("','", $values)."'";
            DB::statement("ALTER TABLE links ADD CONSTRAINT links_{$column}_check CHECK ({$column} IN ({$list}))");
        }
        DB::statement("ALTER TABLE links ADD CONSTRAINT links_url_hash_format CHECK (url_hash ~ '^[0-9a-f]{64}$')");

        /*
         * saved_links: a user's private instance of a canonical link. Overrides, notes, visibility and
         * sharing settings are isolated per user.
         */
        Schema::create('saved_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_id')->constrained()->restrictOnDelete();

            $table->string('custom_title', 300)->nullable();
            $table->text('custom_description')->nullable();
            $table->text('custom_image_url')->nullable();
            $table->string('custom_image_path')->nullable();
            $table->string('custom_source_type', 20)->nullable();
            $table->text('notes')->nullable();

            $table->string('visibility', 10)->default('PRIVATE');
            $table->boolean('allow_resharing')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();

            // AI output applied to this saved instance (generated from public source content only).
            $table->foreignId('ai_category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedBigInteger('ai_applied_analysis_id')->nullable();

            $table->unsignedInteger('popularity_score')->default(0);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            // One saved instance per user per canonical link; re-saving restores the soft-deleted row.
            $table->unique(['user_id', 'link_id']);
            // Target for composite foreign keys (cross-user safety in collection_items).
            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'deleted_at', 'created_at']);
            $table->index(['visibility', 'deleted_at', 'published_at']);
            $table->index(['link_id']);
        });
        DB::statement("ALTER TABLE saved_links ADD CONSTRAINT saved_links_visibility_check CHECK (visibility IN ('PRIVATE','PUBLIC'))");
        DB::statement("ALTER TABLE saved_links ADD CONSTRAINT saved_links_custom_source_type_check CHECK (custom_source_type IS NULL OR custom_source_type IN ('WEBSITE','ARTICLE','VIDEO','PLAYLIST','PDF','REPOSITORY','IMAGE','AUDIO','DOCUMENT','OTHER'))");

        // Full-text search documents, maintained by App\Services\Search\SearchIndexer.
        // search_public: data that may be shown to anyone allowed to see the link.
        // search_private: owner-only data (notes). Never queried for non-owners.
        DB::statement('ALTER TABLE saved_links ADD COLUMN search_public tsvector');
        DB::statement('ALTER TABLE saved_links ADD COLUMN search_private tsvector');
        DB::statement('CREATE INDEX saved_links_search_public_idx ON saved_links USING GIN (search_public)');
        DB::statement('CREATE INDEX saved_links_search_private_idx ON saved_links USING GIN (search_private)');
        DB::statement("CREATE INDEX saved_links_public_feed_idx ON saved_links (published_at DESC) WHERE visibility = 'PUBLIC' AND deleted_at IS NULL");
        DB::statement("CREATE INDEX saved_links_public_popular_idx ON saved_links (popularity_score DESC) WHERE visibility = 'PUBLIC' AND deleted_at IS NULL");

        Schema::create('saved_link_tags', function (Blueprint $table) {
            $table->foreignId('saved_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('source', 10)->default('USER');
            $table->timestampTz('created_at')->useCurrent();
            $table->primary(['saved_link_id', 'tag_id']);
            $table->index('tag_id');
        });
        DB::statement("ALTER TABLE saved_link_tags ADD CONSTRAINT saved_link_tags_source_check CHECK (source IN ('USER','AI'))");

        // Unique-per-day viewer fingerprints used for abuse-resistant popularity.
        Schema::create('link_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('saved_link_id')->constrained()->cascadeOnDelete();
            $table->char('viewer_hash', 64);
            $table->date('viewed_on');
            $table->unique(['saved_link_id', 'viewer_hash', 'viewed_on']);
            $table->index('viewed_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_views');
        Schema::dropIfExists('saved_link_tags');
        Schema::dropIfExists('saved_links');
        Schema::dropIfExists('links');
    }
};
