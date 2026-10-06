<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    /**
     * Run the migrations.
     *
     * Remove the global UNIQUE constraints on ip and mac so that soft-deleted
     * rows no longer block re-creation with the same ip/mac. Uniqueness for
     * active records is enforced at the application layer:
     *   - EntityIntegratorEquipmentRequest::uniqueRule() uses ->whereNull('deleted_at')
     *   - EntityIntegratorEquipmentService::findOrCreate() upserts by hardware identity
     */
    public function up(): void
    {
        Schema::table('entity_integrator_equipments', function (Blueprint $table) {
            $table->dropUnique(['ip']);
            $table->dropUnique(['mac']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Irreversível de propósito: depois desta migration a tabela aceita ip/mac
     * repetidos (registros excluídos e a massa fake usa 0.0.0.0), então
     * recriar o UNIQUE global falhava e travava `migrate:refresh`/`reset` no
     * meio, deixando o banco pela metade. A unicidade dos ativos segue na
     * aplicação.
     */
    public function down(): void
    {
    }
};
