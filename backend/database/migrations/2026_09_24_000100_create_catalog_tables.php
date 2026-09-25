<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference data (characters, weapons / light cones, artifact / relic sets) shared by imports and builds.
     * Rows are keyed by (game, key) where key is the normalized English name (see App\Support\Key).
     */
    public function up(): void
    {
        Schema::create('game_characters', function (Blueprint $table) {
            $table->id();
            $table->string('game', 16);
            $table->string('key', 120);
            $table->string('name', 160);
            $table->string('element', 20)->nullable();
            $table->unsignedTinyInteger('rarity')->nullable();
            $table->string('weapon_type', 30)->nullable()->comment('Genshin weapon type or HSR path');
            $table->string('external_id', 40)->nullable()->comment('In-game ID (Enka / yatta)');
            $table->string('icon_url', 500)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['game', 'key']);
            $table->index(['game', 'external_id']);
        });

        Schema::create('game_weapons', function (Blueprint $table) {
            $table->id();
            $table->string('game', 16);
            $table->string('key', 160);
            $table->string('name', 200);
            $table->unsignedTinyInteger('rarity')->nullable();
            $table->string('weapon_type', 30)->nullable()->comment('Genshin weapon type or HSR path');
            $table->string('external_id', 40)->nullable();
            $table->string('icon_url', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['game', 'key']);
            $table->index(['game', 'external_id']);
        });

        Schema::create('game_sets', function (Blueprint $table) {
            $table->id();
            $table->string('game', 16);
            $table->string('key', 160);
            $table->string('name', 200);
            $table->string('kind', 16)->comment('artifact | relic | planar');
            $table->string('external_id', 40)->nullable();
            $table->string('icon_url', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['game', 'key']);
            $table->index(['game', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sets');
        Schema::dropIfExists('game_weapons');
        Schema::dropIfExists('game_characters');
    }
};
