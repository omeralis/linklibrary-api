<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_security_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->string('trigger', 20);
            $table->string('status', 12)->default('PENDING');
            $table->string('verdict', 12)->nullable();
            // Per-provider outcome: [{provider, verdict, threats[], error_code}] — no secrets, no private data.
            $table->jsonb('provider_results')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['link_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE link_security_scans ADD CONSTRAINT link_security_scans_status_check CHECK (status IN ('PENDING','PROCESSING','COMPLETED','FAILED'))");
        DB::statement("ALTER TABLE link_security_scans ADD CONSTRAINT link_security_scans_verdict_check CHECK (verdict IS NULL OR verdict IN ('PENDING','CLEAR','SUSPICIOUS','MALICIOUS','UNKNOWN'))");

        Schema::table('links', function (Blueprint $table) {
            $table->foreign('last_security_scan_id')->references('id')->on('link_security_scans')->nullOnDelete();
        });

        Schema::create('link_security_appeals', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('saved_link_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('security_status_at_submission', 12);
            $table->text('reason');
            $table->string('status', 10)->default('PENDING');
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reviewer_notes')->nullable();
            $table->string('resolution_verdict', 12)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at']);
        });
        DB::statement("ALTER TABLE link_security_appeals ADD CONSTRAINT link_security_appeals_status_check CHECK (status IN ('PENDING','APPROVED','REJECTED'))");
        DB::statement("CREATE UNIQUE INDEX link_security_appeals_one_pending ON link_security_appeals (link_id, user_id) WHERE status = 'PENDING'");

        Schema::create('link_extraction_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('extraction_version');
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('status', 12)->default('PROCESSING');
            $table->string('extractor', 40)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            // Safe diagnostics only (host, path, timings, content type). Never query strings or bodies.
            $table->jsonb('diagnostics')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['link_id', 'created_at']);
        });
        DB::statement("ALTER TABLE link_extraction_jobs ADD CONSTRAINT link_extraction_jobs_status_check CHECK (status IN ('PROCESSING','COMPLETED','PARTIAL','FAILED','BLOCKED','SKIPPED'))");

        Schema::create('link_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('link_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ai_version');
            $table->string('status', 12)->default('PROCESSING');
            $table->string('provider', 40);
            $table->string('model', 120)->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->string('suggested_title', 300)->nullable();
            $table->string('short_summary', 600)->nullable();
            $table->text('detailed_summary')->nullable();
            $table->string('suggested_category_slug', 80)->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->jsonb('suggested_tags')->nullable();
            $table->string('language', 12)->nullable();
            $table->string('content_type', 20)->nullable();
            // Supplementary only: may raise a link to SUSPICIOUS, can never mark it safe.
            $table->boolean('suspicious')->default(false);
            $table->jsonb('suspicious_reasons')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->index(['link_id', 'created_at']);
        });
        DB::statement("ALTER TABLE link_ai_analyses ADD CONSTRAINT link_ai_analyses_status_check CHECK (status IN ('PROCESSING','COMPLETED','FAILED','SKIPPED'))");

        Schema::table('links', function (Blueprint $table) {
            $table->foreign('current_ai_analysis_id')->references('id')->on('link_ai_analyses')->nullOnDelete();
        });
        Schema::table('saved_links', function (Blueprint $table) {
            $table->foreign('ai_applied_analysis_id')->references('id')->on('link_ai_analyses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('saved_links', fn (Blueprint $t) => $t->dropForeign(['ai_applied_analysis_id']));
        Schema::table('links', fn (Blueprint $t) => $t->dropForeign(['current_ai_analysis_id']));
        Schema::dropIfExists('link_ai_analyses');
        Schema::dropIfExists('link_extraction_jobs');
        Schema::dropIfExists('link_security_appeals');
        Schema::table('links', fn (Blueprint $t) => $t->dropForeign(['last_security_scan_id']));
        Schema::dropIfExists('link_security_scans');
    }
};
