<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Gleich AI Brain 2026_09_24_151403. Nur mit `vault.quellen` und `run_migrations`. */
return new class extends Migration
{
    public function up(): void
    {
        $quellen = config('vault.tables.sources', 'vault_sources');
        $benutzer = (new (config('vault.user_model')))->getTable();

        Schema::create($quellen, function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            // Nur zur Anzeige: wo die Daten wirklich liegen. Die Anwendung verbindet sich nie selbst dorthin.
            $table->string('host')->nullable();
            $table->string('share_name')->nullable();
            $table->string('mount_path');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create(config('vault.tables.source_user', 'vault_source_user'), function (Blueprint $table) use ($quellen, $benutzer) {
            $table->foreignId('vault_source_id')->constrained($quellen)->cascadeOnDelete();
            $table->foreignId('user_id')->constrained($benutzer)->cascadeOnDelete();
            $table->string('role')->default('viewer');
            $table->timestamps();

            $table->primary(['vault_source_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('vault.tables.source_user', 'vault_source_user'));
        Schema::dropIfExists(config('vault.tables.sources', 'vault_sources'));
    }
};
