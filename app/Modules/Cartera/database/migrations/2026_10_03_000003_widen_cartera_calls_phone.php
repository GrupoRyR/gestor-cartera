<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phone of a call may be picked from the client's contacts, and Core
 * keeps a contact's phone in a string(255) where SAP often puts several
 * numbers; 40 characters refused those calls. The column now fits any
 * contact phone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartera_calls', function (Blueprint $table) {
            $table->string('phone', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('cartera_calls', function (Blueprint $table) {
            $table->string('phone', 40)->change();
        });
    }
};
