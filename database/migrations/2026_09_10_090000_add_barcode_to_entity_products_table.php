<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GAP fechado (revisão pós-Fase 4 — "melhorar o módulo de estoque"):
 * código de barras (EAN/UPC de fabricante, OU etiqueta interna gerada pela
 * clínica) pra acelerar lançamento de movimentação em volume alto via
 * leitor de código de barras USB/Bluetooth (que digita os dígitos + Enter,
 * não precisa de driver/hardware especial — ver
 * MovementFormModal.vue::onBarcodeScanned()).
 *
 * Nullable + único POR CLÍNICA (não global — duas clínicas podem cadastrar
 * o mesmo produto de fabricantes diferentes com o mesmo EAN sem colidir).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entity_products', function (Blueprint $table) {
            $table->string('barcode')->nullable()->after('sku');
            $table->unique(['entity_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::table('entity_products', function (Blueprint $table) {
            $table->dropUnique(['entity_id', 'barcode']);
            $table->dropColumn('barcode');
        });
    }
};
