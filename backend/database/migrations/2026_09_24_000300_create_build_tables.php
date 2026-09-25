<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recommended builds scraped from community websites.
     */
    public function up(): void
    {
        Schema::create('build_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique()->comment('Matches a scraper class, e.g. prydwen.hsr');
            $table->string('game', 16);
            $table->string('name', 100);
            $table->string('base_url', 255);
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('builds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_character_id')->constrained()->cascadeOnDelete();
            $table->string('variant', 120)->comment('Build name on the source, e.g. "Main DPS"');
            $table->string('role', 60)->nullable();
            $table->boolean('recommended')->default(false)->comment('The source marks this variant as its main recommendation');
            $table->unsignedSmallInteger('position')->default(0);
            $table->json('weapons')->comment('[{key, name, rank, note}]');
            $table->json('sets')->comment('[{rank, kind, pieces: [{key, name, count}], note}]');
            $table->json('main_stats')->comment('{slot: [{stat, priority}]}');
            $table->json('substats')->comment('[{stat, priority}]');
            $table->json('talent_priority')->nullable();
            $table->json('stat_targets')->nullable()->comment('[{stat, min, max, text}]');
            $table->json('teams')->nullable();
            $table->text('notes')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('game_version', 20)->nullable();
            $table->string('content_hash', 64);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamp('changed_at')->nullable();
            $table->timestamps();

            $table->unique(['build_source_id', 'game_character_id', 'variant']);
            $table->index('game_character_id');
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('build_source_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->comment('running | success | partial | failed');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('stats')->nullable();
            $table->text('log')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('builds');
        Schema::dropIfExists('build_sources');
    }
};
