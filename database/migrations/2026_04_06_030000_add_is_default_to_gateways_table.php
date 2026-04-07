<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciona o campo is_default à tabela gateways.
 *
 * Regras de negócio:
 *   - Apenas UM gateway pode ter is_default = true por vez.
 *   - Garantia de unicidade feita na camada de aplicação (GatewayDefaultService),
 *     pois não é possível usar unique constraint booleano "parcial" de forma portável
 *     entre SQLite (dev) e MySQL/PostgreSQL (prod).
 *   - Somente gateways ativos com credenciais ativas podem ser o padrão.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('active');
            $table->index('is_default', 'gateways_is_default_idx');
        });
    }

    public function down(): void
    {
        Schema::table('gateways', function (Blueprint $table) {
            $table->dropIndex('gateways_is_default_idx');
            $table->dropColumn('is_default');
        });
    }
};
