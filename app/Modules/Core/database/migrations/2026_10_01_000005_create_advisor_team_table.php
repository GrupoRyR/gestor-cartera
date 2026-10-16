<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team of each sales manager (gerente_comercial): the SAP advisors whose
 * portfolio they see (Part A.5, docs/arquitectura.md §6.2). The Equipo
 * screen fills it; CarteraRepository reads it to scope the portfolio. An
 * advisor may be in several teams.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_team', function (Blueprint $table) {
            // The sales manager. Users are soft-deleted, so the team survives
            // a removal and comes back with a restore.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('advisor_id')->index()->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['user_id', 'advisor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_team');
    }
};
