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
         * link_access_grants: explicit edges of the access graph. A grant with parent_grant_id = NULL is a
         * direct grant by the owner; otherwise it was created by a viewer through their (valid) parent grant.
         * A grant is effective only while it and every ancestor are ACTIVE (see AccessGraphService).
         */
        Schema::create('link_access_grants', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('saved_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grantor_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('grantee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_grant_id')->nullable()->constrained('link_access_grants')->cascadeOnDelete();
            $table->unsignedBigInteger('invitation_id')->nullable();
            $table->unsignedSmallInteger('depth')->default(1);
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 40)->nullable();
            $table->timestampsTz();

            $table->index(['saved_link_id', 'grantee_user_id', 'status']);
            $table->index(['grantee_user_id', 'status']);
            $table->index('parent_grant_id');
        });
        DB::statement("ALTER TABLE link_access_grants ADD CONSTRAINT link_access_grants_status_check CHECK (status IN ('ACTIVE','REVOKED'))");
        DB::statement('ALTER TABLE link_access_grants ADD CONSTRAINT link_access_grants_no_self CHECK (grantor_user_id <> grantee_user_id)');
        DB::statement("ALTER TABLE link_access_grants ADD CONSTRAINT link_access_grants_revoked_consistency CHECK ((status = 'REVOKED') = (revoked_at IS NOT NULL))");
        // At most one ACTIVE grant per (link, grantor, grantee) edge.
        DB::statement("CREATE UNIQUE INDEX link_access_grants_active_edge_unique ON link_access_grants (saved_link_id, grantor_user_id, grantee_user_id) WHERE status = 'ACTIVE'");

        Schema::create('link_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('saved_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            // Grant through which a non-owner inviter holds access (NULL when the owner invites).
            $table->foreignId('inviter_grant_id')->nullable()->constrained('link_access_grants')->cascadeOnDelete();
            $table->string('email', 254);
            // SHA-256 of the random token. The plaintext token is only ever sent by e-mail.
            $table->char('token_hash', 64)->unique();
            $table->string('status', 10)->default('PENDING');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['saved_link_id', 'status']);
            $table->index(['email', 'status']);
            $table->index(['status', 'expires_at']);
        });
        DB::statement("ALTER TABLE link_invitations ADD CONSTRAINT link_invitations_status_check CHECK (status IN ('PENDING','ACCEPTED','REVOKED','EXPIRED'))");
        DB::statement('ALTER TABLE link_invitations ADD CONSTRAINT link_invitations_email_lower CHECK (email = lower(email))');
        DB::statement("CREATE UNIQUE INDEX link_invitations_pending_unique ON link_invitations (saved_link_id, inviter_user_id, email) WHERE status = 'PENDING'");

        Schema::table('link_access_grants', function (Blueprint $table) {
            $table->foreign('invitation_id')->references('id')->on('link_invitations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('link_access_grants', function (Blueprint $table) {
            $table->dropForeign(['invitation_id']);
        });
        Schema::dropIfExists('link_invitations');
        Schema::dropIfExists('link_access_grants');
    }
};
