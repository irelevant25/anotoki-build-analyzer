<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A player's game account and everything imported from it.
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('game', 16);
            $table->string('uid', 20)->nullable();
            $table->string('nickname', 100)->nullable();
            $table->unsignedSmallInteger('level')->nullable()->comment('Adventure Rank / Trailblaze Level');
            $table->unsignedTinyInteger('world_level')->nullable()->comment('World Level / Equilibrium Level');
            $table->string('signature', 300)->nullable();
            $table->boolean('has_full_inventory')->default(false);
            $table->timestamp('last_import_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['game', 'uid']);
        });

        Schema::create('import_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('game', 16);
            $table->string('source', 32)->comment('enka | good | hsr_scanner');
            $table->string('status', 16)->comment('success | partial | failed');
            $table->string('file_name', 255)->nullable();
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('account_characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_character_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedTinyInteger('ascension')->nullable();
            $table->unsignedTinyInteger('rank')->default(0)->comment('Constellation / Eidolon');
            $table->json('talents')->nullable();
            $table->json('final_stats')->nullable()->comment('Total stats as shown in game (only Enka provides them)');
            $table->string('source', 32);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'game_character_id']);
        });

        Schema::create('account_weapons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_weapon_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level')->default(1);
            $table->unsignedTinyInteger('ascension')->nullable();
            $table->unsignedTinyInteger('refinement')->default(1)->comment('Refinement / Superimposition');
            $table->foreignId('equipped_character_id')->nullable()->constrained('game_characters')->nullOnDelete();
            $table->boolean('locked')->default(false);
            $table->string('external_uid', 64)->nullable();
            $table->string('source', 32);
            $table->timestamps();

            $table->index(['account_id', 'equipped_character_id']);
        });

        Schema::create('account_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_set_id')->constrained()->cascadeOnDelete();
            $table->string('slot', 16);
            $table->unsignedTinyInteger('rarity')->default(5);
            $table->unsignedTinyInteger('level')->default(0);
            $table->string('main_stat', 32);
            $table->double('main_value')->nullable();
            $table->json('substats')->comment('[{key, value, rolls}]');
            $table->foreignId('equipped_character_id')->nullable()->constrained('game_characters')->nullOnDelete();
            $table->boolean('locked')->default(false);
            $table->string('external_uid', 64)->nullable();
            $table->string('source', 32);
            $table->timestamps();

            $table->index(['account_id', 'slot']);
            $table->index(['account_id', 'equipped_character_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_items');
        Schema::dropIfExists('account_weapons');
        Schema::dropIfExists('account_characters');
        Schema::dropIfExists('import_runs');
        Schema::dropIfExists('accounts');
    }
};
