<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who signs each company's Cobro mails (weekly collection, circularization
 * and replies): name, title, mobile and PBX extension. Without a row the
 * mails use the defaults of config cobro.firma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cobro_firmas', function (Blueprint $table) {
            $table->id();
            $table->string('company', 3)->unique();
            $table->string('name', 100);
            $table->string('title', 100);
            $table->string('mobile', 30);
            // Extension of the company PBX; null = none.
            $table->string('extension', 6)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobro_firmas');
    }
};
