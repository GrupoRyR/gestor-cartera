<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partially paid commitments (status "abonado"): the amount the client paid
 * and when it was recorded. Both are null for every other status. The
 * remaining balance is derived (amount - paid_amount), never stored. The
 * status column already fits the new value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->decimal('paid_amount', 18, 2)->nullable()->after('amount');
            $table->timestamp('partially_paid_at')->nullable()->after('fulfilled_at');
        });
    }

    public function down(): void
    {
        Schema::table('commitments', function (Blueprint $table) {
            $table->dropColumn(['paid_amount', 'partially_paid_at']);
        });
    }
};
