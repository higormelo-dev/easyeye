<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migração de lentes IOL pro estoque (fase 1 do plano) — dois campos
 * GENÉRICOS novos em entity_products, não exclusivos de lente:
 * `manufacturer` (fabricante do item) e `image_path` (foto de produto).
 * Nenhum dos dois existia no módulo de estoque até aqui (confirmado:
 * ProductFormModal.vue/Products/Index.vue/EntityProductResource não têm
 * esses campos) — ficam nullable, e por enquanto só o fluxo de lentes IOL
 * os preenche (fase 4). Qualquer outro tipo de produto pode passar a usá-los
 * no futuro sem migration nova.
 *
 * Puramente aditiva/reversível — não mexe em dado existente.
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('entity_products', function (Blueprint $table) {
            $table->string('manufacturer')->nullable()->after('name');
            $table->string('image_path')->nullable()->after('sale_price');
        });
    }

    public function down(): void
    {
        Schema::table('entity_products', function (Blueprint $table) {
            $table->dropColumn(['manufacturer', 'image_path']);
        });
    }
};
