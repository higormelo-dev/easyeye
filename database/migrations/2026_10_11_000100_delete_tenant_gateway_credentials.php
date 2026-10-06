<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Apaga as credenciais de gateway "por clínica" (entity_id preenchido ou
 * scope = 'tenant'), inclusive as já revogadas/soft-deleted.
 *
 * Clínica não tem gateway próprio: só a credencial global do dono do SaaS é
 * usada (GatewayCredentialResolver ignora qualquer linha com entity_id).
 * Segredo sem uso não deve ficar no banco, então é exclusão física — a
 * credencial global (scope = 'global' e entity_id NULL) fica intacta.
 *
 * Sem volta (down vazio, de propósito): os segredos apagados não devem
 * voltar, e o produto não tem mais onde usá-los. O enum CredentialScope só
 * tem o case Global a partir daqui.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::table('gateway_credentials')
            ->where(static fn ($q) => $q->whereNotNull('entity_id')->orWhere('scope', '!=', 'global'))
            ->delete();
    }

    public function down(): void
    {
        // No-op documentado: exclusão de segredos sem uso não tem rollback.
    }
};
