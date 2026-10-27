<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a user must never change the module's audit trail (S-14): the
 * link to "users" restricts the delete instead of nulling "user_id". Users
 * are soft-deleted, so only a hard delete of someone with events is refused.
 * The indexes stay; SQLite rebuilds the table to change the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comisiones_bitacora', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('comisiones_bitacora', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }
};
