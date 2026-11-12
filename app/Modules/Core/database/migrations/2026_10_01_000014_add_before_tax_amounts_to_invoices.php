<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The pre-VAT figures of each open item: the robot's cartera batch brings
 * the original amount ("base_gravable") and the balance ("saldo_base")
 * without VAT, and advisors quote them to clients. Both are nullable: manual
 * uploads and the snapshots imported before this migration do not have
 * them, and a missing value must never read as zero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('amount_before_tax', 18, 2)->nullable()->after('balance');
            // Negative for credit notes and unapplied payments, like "balance".
            $table->decimal('balance_before_tax', 18, 2)->nullable()->after('amount_before_tax');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['amount_before_tax', 'balance_before_tax']);
        });
    }
};
