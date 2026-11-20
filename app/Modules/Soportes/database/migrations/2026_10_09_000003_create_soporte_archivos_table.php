<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The files of a receipt (PDF, JPG or PNG). path is where the file sits on
 * the private disk, under a generated name; original_name is only shown, never
 * used to read the file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soporte_archivos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('soporte_id')->constrained('soportes')->cascadeOnDelete();
            $table->string('path')->unique();
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soporte_archivos');
    }
};
