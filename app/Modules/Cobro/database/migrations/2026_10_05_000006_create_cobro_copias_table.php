<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who each company copies on the weekly collection mail. Without a row the
 * mail uses the defaults of config cobro.copias.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_copias', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3)->unique();
            // Addresses copied on every mail.
            $table->json('fixed');
            // Whether the client's advisor (SAP OSLP.Email) is copied.
            $table->boolean('advisor')->default(true);
            // List of {group, emails}: managers copied for the clients of a SAP group.
            $table->json('managers');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_copias');
    }
};
