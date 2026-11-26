<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Gleich AI Brain `note_shares` (2026_05_12_095440 + area_key 2026_07_08).
 * Im Manager heisst die Tabelle `vault_shares` — `note_shares` gehoert dort
 * dem CRM.
 */
return new class extends Migration
{
    public function up(): void
    {
        $benutzer = (new (config('vault.user_model')))->getTable();

        Schema::create(config('vault.tables.shares', 'vault_shares'), function (Blueprint $table) use ($benutzer) {
            $table->id();
            $table->foreignId('user_id')->constrained($benutzer)->cascadeOnDelete();
            $table->string('vault_path');
            $table->string('area_key')->nullable();
            $table->string('token', 64)->unique();
            $table->string('password_hash')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'vault_path'], 'vault_shares_user_path_index');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('vault.tables.shares', 'vault_shares'));
    }
};
