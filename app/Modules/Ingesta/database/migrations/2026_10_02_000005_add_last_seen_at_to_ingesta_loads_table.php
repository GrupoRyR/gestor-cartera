<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an accepted load was last received: the robot sends the same batch
 * again at each slot while SAP does not change, and that duplicate proves
 * the load works without storing a new one. Null on older rows means
 * received_at; no backfill. In the app timezone, like received_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingesta_loads', function (Blueprint $table) {
            // The watch reads it after company, dataset and status, whose index already narrows it.
            $table->dateTime('last_seen_at')->nullable()->after('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('ingesta_loads', function (Blueprint $table) {
            $table->dropColumn('last_seen_at');
        });
    }
};
