<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Gleich dem Schema in AI Brain (2026_07_07_150000). Den globalen Bereich
 * legt das Produkt an — Name und Ordner sind seine Sache (AI Brain
 * „Allgemein“ → `_shared`, Manager Firmen-Vault → `firma`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $raeume = config('vault.tables.spaces', 'vault_spaces');
        $mitglieder = config('vault.tables.space_user', 'vault_space_user');
        $benutzer = (new (config('vault.user_model')))->getTable();

        Schema::create($raeume, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_global')->default(false);
            $table->timestamps();
        });

        Schema::create($mitglieder, function (Blueprint $table) use ($raeume, $benutzer) {
            $table->foreignId('vault_space_id')->constrained($raeume)->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($benutzer)->cascadeOnDelete();
            // 'editor' = lesen + schreiben, 'viewer' = nur lesen.
            $table->string('role')->default('viewer');
            $table->timestamps();

            $table->primary(['vault_space_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('vault.tables.space_user', 'vault_space_user'));
        Schema::dropIfExists(config('vault.tables.spaces', 'vault_spaces'));
    }
};
