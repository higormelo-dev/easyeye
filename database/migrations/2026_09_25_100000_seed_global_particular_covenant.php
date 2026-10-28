<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cria o convênio global "PARTICULAR" (entity_id nulo, visível a todas as
 * clínicas). O código já assume que ele existe — PatientService esconde a
 * carteirinha quando o convênio é Particular, BillingService trata convênio
 * sem registro ANS como faturamento particular — mas nada o criava: o
 * CovenantsSeeder só semeia as operadoras da ANS. Como patients.covenant_id
 * é NOT NULL, paciente particular não tinha onde entrar (import de planilha
 * quebrava com violação de NOT NULL). No sistema de origem (smart_oftal)
 * "PARTICULAR" também era um registro comum de convênio.
 */
return new class() extends Migration {
    public function up(): void
    {
        $exists = DB::table('covenants')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->whereRaw('upper(name) = ?', ['PARTICULAR'])
            ->exists();

        if ($exists) {
            return;
        }

        $lastCode = DB::table('covenants')
            ->whereNull('entity_id')
            ->where('code', 'like', 'CVP-%')
            ->orderByDesc('code')
            ->value('code');

        $next = $lastCode ? ((int) substr($lastCode, 4)) + 1 : 1;

        DB::table('covenants')->insert([
            'id'         => (string) Str::uuid7(),
            'entity_id'  => null,
            'code'       => sprintf('CVP-%010d', $next),
            'name'       => 'PARTICULAR',
            'color'      => '#6C757D',
            'table'      => true,
            'active'     => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Intencionalmente não remove: patients.covenant_id tem cascadeOnDelete,
     * apagar este registro apagaria em cascata todo paciente particular.
     */
    public function down(): void
    {
    }
};
