<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->string('visibility', 10)->default('PRIVATE');
            $table->text('cover_image_url')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'user_id']);
            $table->index(['user_id', 'sort_order']);
            $table->index(['visibility', 'user_id']);
        });
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_visibility_check CHECK (visibility IN ('PRIVATE','PUBLIC'))");

        /*
         * collection_items carries user_id so that composite foreign keys guarantee, at the database level,
         * that a collection can only contain saved links belonging to the same user.
         */
        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('collection_id');
            $table->unsignedBigInteger('saved_link_id');
            $table->unsignedBigInteger('user_id');
            $table->integer('position')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['collection_id', 'saved_link_id']);
            $table->index(['collection_id', 'position']);
            $table->index('saved_link_id');

            $table->foreign(['collection_id', 'user_id'])->references(['id', 'user_id'])->on('collections')->cascadeOnDelete();
            $table->foreign(['saved_link_id', 'user_id'])->references(['id', 'user_id'])->on('saved_links')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
    }
};
