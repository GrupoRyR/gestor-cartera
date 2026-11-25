<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Load failure reports: one row per failed attempt the robot (or the Bases
 * SAP reader) reported, stored once per report_id. Times are in the app
 * timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingesta_load_failures', function (Blueprint $table) {
            $table->id();
            // The reporter's idempotency key: a report sent again is stored once.
            $table->uuid('report_id')->unique();
            // FailureSource value: robot or bases_sap.
            $table->string('source', 10);
            // The run of the reporter; every attempt of one run shares it.
            $table->uuid('run_id');
            // CompanyCode value; no foreign key to Core's companies table.
            $table->string('company', 3);
            // Dataset value of the load that failed.
            $table->string('dataset', 30);
            // The scheduled time of the load that failed.
            $table->dateTime('slot_at');
            // FailureStage and FailureCause values.
            $table->string('stage', 20);
            $table->string('cause', 20);
            $table->boolean('transient');
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('max_attempts');
            // True on the last attempt: the load will not be retried.
            $table->boolean('final');
            // Cleaned and cut by RecordLoadFailure.
            $table->string('message', 300);
            $table->dateTime('occurred_at');
            $table->dateTime('received_at');

            $table->index(['company', 'dataset', 'slot_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingesta_load_failures');
    }
};
