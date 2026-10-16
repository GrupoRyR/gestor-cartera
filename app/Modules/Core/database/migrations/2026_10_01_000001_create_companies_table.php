<?php

use App\Modules\Core\Contracts\Enums\CompanyCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The companies of the group and the parameters admins edit. Rows are
 * seeded here, from CompanyCode and config('core.companies'), so every
 * environment has both companies right after migrating.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->string('code', 3)->primary();
            $table->string('name');
            $table->unsignedSmallInteger('grace_days');
            $table->unsignedSmallInteger('due_soon_days');
            $table->timestamps();
        });

        $now = now();

        DB::table('companies')->insert(array_map(fn (CompanyCode $code): array => [
            'code' => $code->value,
            'name' => $code->label(),
            'grace_days' => (int) config("core.companies.{$code->value}.grace_days"),
            'due_soon_days' => (int) config("core.companies.{$code->value}.due_soon_days"),
            'created_at' => $now,
            'updated_at' => $now,
        ], CompanyCode::cases()));
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
