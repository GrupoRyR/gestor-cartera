<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rows of an accepted load, in chunks of ChunkCodec::ROWS rows: each
 * payload is the base64 of a gzip of the JSON list of value lists, in the
 * column order of ingesta_loads.columns. Consumers read one chunk at a
 * time, so a large snapshot never sits whole in memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingesta_load_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained('ingesta_loads')->cascadeOnDelete();
            // Position of the chunk within the load, from 0.
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('row_count');
            $table->longText('payload');

            $table->unique(['load_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingesta_load_chunks');
    }
};
