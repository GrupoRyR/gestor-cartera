<?php

use App\Modules\Core\Infrastructure\Audit\AuditChain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper evidence for the audit trail (S-14): every row of "audit_logs"
 * carries an HMAC of its contents and of the row before it ("chain_hash"),
 * and the one-row "audit_chain_head" remembers the last link, so the daily
 * core:verificar-auditoria notices an altered, deleted or forged row.
 *
 * Unlike other migrations this one also writes data: the rows recorded
 * before the chain existed have no hash, and the verifier would reject
 * them, so they are sealed here, in id order, and the head is set to the
 * last of them. The key is derived from APP_KEY (see AuditChain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->char('chain_hash', 64)->nullable();
        });

        Schema::create('audit_chain_head', function (Blueprint $table) {
            // A single row, id 1, locked by every append.
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_id')->nullable();
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('audit_chain_head')->insert(['id' => AuditChain::HEAD_ID, 'last_id' => null, 'last_hash' => null, 'updated_at' => now()]);

        app(AuditChain::class)->seal();
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_head');

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('chain_hash');
        });
    }
};
