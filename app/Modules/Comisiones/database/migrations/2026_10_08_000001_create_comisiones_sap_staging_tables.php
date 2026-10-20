<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SAP staging of the commission settlement (SGX only), fed by the
 * BatchAccepted listener from the Ingesta datasets ventas, recaudos, cruces,
 * maestro_clientes and maestro_vendedores. Every SAP row is upserted by its
 * natural key ("clave"), so a repeated load leaves the same rows.
 *
 * Amounts are decimal(18,2) and exchange rates decimal(18,6); load_id is the
 * Ingesta load that last wrote the row (no foreign key across modules).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Report 1: one row per line of an A/R invoice (13) or credit note (14).
        Schema::create('comisiones_ventas', function (Blueprint $table) {
            $table->id();
            // "{tipo_objeto}-{doc_entry}-{linea}".
            $table->string('clave', 60)->unique();
            $table->unsignedBigInteger('load_id')->nullable();
            $table->unsignedInteger('tipo_objeto');
            $table->unsignedBigInteger('doc_entry');
            $table->unsignedBigInteger('numero_documento');
            $table->unsignedInteger('linea');
            // factura | nota_debito | nota_credito.
            $table->string('clase_documento', 20);
            // anulado | anulacion | cerrado | abierto.
            $table->string('estado_documento', 20);
            $table->date('fecha_documento');
            $table->date('fecha_contabilizacion')->nullable();
            $table->integer('slp_code');
            $table->string('vendedor')->nullable();
            $table->string('canal', 100)->nullable();
            $table->string('codigo_cliente', 50);
            $table->string('cliente')->nullable();
            $table->string('nit', 50)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('codigo_articulo', 100)->nullable();
            $table->string('descripcion')->nullable();
            // Signed: credit notes are negative.
            $table->decimal('total', 18, 2);
            $table->decimal('iva', 18, 2)->nullable();
            $table->string('moneda', 10)->nullable();
            $table->decimal('trm', 18, 6)->nullable();
            $table->timestamps();

            $table->index('fecha_documento');
            $table->index(['tipo_objeto', 'doc_entry']);
        });

        // Report 2: one row per application of an incoming payment to an invoice.
        Schema::create('comisiones_recaudos', function (Blueprint $table) {
            $table->id();
            // "{doc_entry_pago}-{linea}".
            $table->string('clave', 60)->unique();
            $table->unsignedBigInteger('load_id')->nullable();
            $table->unsignedBigInteger('doc_entry_pago');
            $table->unsignedBigInteger('numero_pago');
            $table->unsignedInteger('linea');
            $table->date('fecha_pago');
            // anulado | vigente.
            $table->string('estado_pago', 20);
            $table->integer('tipo_documento');
            $table->unsignedBigInteger('doc_entry_factura');
            $table->unsignedBigInteger('numero_factura')->nullable();
            $table->date('fecha_factura')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->string('codigo_cliente', 50);
            $table->string('nombre_cliente')->nullable();
            $table->integer('slp_code');
            $table->string('vendedor')->nullable();
            $table->decimal('valor_factura', 18, 2)->nullable();
            $table->decimal('base_gravable', 18, 2)->nullable();
            $table->decimal('iva_factura', 18, 2)->nullable();
            $table->decimal('descuento_factura', 18, 2)->nullable();
            $table->string('moneda', 10)->nullable();
            $table->decimal('trm', 18, 6)->nullable();
            $table->decimal('valor_aplicado', 18, 2);
            $table->decimal('valor_pagado_sin_iva', 18, 2);
            $table->decimal('comision_sap', 18, 2)->nullable();
            $table->timestamps();

            $table->index('fecha_pago');
        });

        // Report 4: SAP crossings (origen "sap") and the manual crossings
        // captured with support (origen "manual"), which need an approval.
        Schema::create('comisiones_cruces', function (Blueprint $table) {
            $table->id();
            // "sap-{numero_cruce}-{linea}" or "manual-{uuid}".
            $table->string('clave', 80)->unique();
            // sap | manual.
            $table->string('origen', 10);
            $table->unsignedBigInteger('load_id')->nullable();
            $table->unsignedBigInteger('numero_cruce')->nullable();
            $table->unsignedInteger('linea')->nullable();
            $table->date('fecha_cruce');
            // anticipo | cliente-proveedor | otro.
            $table->string('tipo_cruce', 30);
            $table->integer('tipo_conciliacion')->nullable();
            // The SAP status of the crossing: anulado | vigente.
            $table->string('estado_cruce', 20);
            $table->string('nit', 50)->nullable();
            $table->string('codigo_cliente', 50);
            $table->string('codigo_proveedor', 50)->nullable();
            $table->string('razon_social')->nullable();
            $table->unsignedBigInteger('doc_entry_factura')->nullable();
            // Text, so a manual crossing can name any invoice.
            $table->string('numero_factura', 50)->nullable();
            $table->date('fecha_factura')->nullable();
            $table->integer('slp_code_factura')->nullable();
            $table->string('vendedor_factura')->nullable();
            $table->decimal('valor_factura', 18, 2)->nullable();
            $table->decimal('base_gravable', 18, 2)->nullable();
            $table->decimal('iva_factura', 18, 2)->nullable();
            $table->decimal('valor_compensado', 18, 2);
            $table->decimal('valor_compensado_sin_iva', 18, 2)->nullable();
            $table->string('usuario', 100)->nullable();
            // Manual capture: the seller the user set, and the support file.
            $table->integer('slp_code_capturado')->nullable();
            $table->string('soporte')->nullable();
            // Approval: pendiente | aprobado | rechazado; SAP rows are aprobado.
            $table->string('estado', 20)->default('aprobado');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revisado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable();
            $table->string('motivo_rechazo', 500)->nullable();
            $table->timestamps();

            $table->index(['fecha_cruce', 'estado']);
        });

        // Report 5 history: the seller of each client from a date on.
        Schema::create('comisiones_asignaciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_cliente', 50);
            $table->integer('slp_code');
            $table->date('vigente_desde');
            $table->unsignedBigInteger('load_id')->nullable();
            $table->timestamps();

            $table->unique(['codigo_cliente', 'vigente_desde']);
        });

        // Report 5: the current master row of each client.
        Schema::create('comisiones_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_cliente', 50)->unique();
            $table->string('nit', 50)->nullable();
            $table->string('razon_social')->nullable();
            $table->integer('slp_code');
            $table->string('vendedor')->nullable();
            $table->string('canal', 100)->nullable();
            $table->string('ciudad', 100)->nullable();
            $table->string('codigo_proveedor', 50)->nullable();
            // activo | inactivo.
            $table->string('estado', 20);
            $table->date('fecha_modificacion')->nullable();
            $table->unsignedBigInteger('load_id')->nullable();
            $table->timestamps();
        });

        // Report 6: the sellers.
        Schema::create('comisiones_vendedores', function (Blueprint $table) {
            $table->id();
            $table->integer('slp_code')->unique();
            $table->string('nombre');
            $table->string('estado', 20);
            $table->string('correo')->nullable();
            $table->string('canal', 100)->nullable();
            $table->string('zona', 100)->nullable();
            $table->date('fecha_modificacion')->nullable();
            $table->unsignedBigInteger('load_id')->nullable();
            $table->timestamps();
        });

        // One row per Ingesta load the module imported.
        Schema::create('comisiones_cargas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('load_id')->unique();
            // The Dataset value: ventas, recaudos, cruces, maestro_clientes, maestro_vendedores.
            $table->string('dataset', 30);
            // robot | manual.
            $table->string('fuente', 20);
            $table->date('corte');
            $table->unsignedInteger('filas');
            $table->unsignedInteger('insertadas');
            $table->unsignedInteger('actualizadas');
            $table->timestamp('recibida_en');
            // aceptada.
            $table->string('estado', 20);
            $table->timestamps();

            $table->index(['dataset', 'corte']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comisiones_cargas');
        Schema::dropIfExists('comisiones_vendedores');
        Schema::dropIfExists('comisiones_clientes');
        Schema::dropIfExists('comisiones_asignaciones');
        Schema::dropIfExists('comisiones_cruces');
        Schema::dropIfExists('comisiones_recaudos');
        Schema::dropIfExists('comisiones_ventas');
    }
};
