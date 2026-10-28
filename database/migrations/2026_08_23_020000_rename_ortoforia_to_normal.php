<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cover Test: "ORTOFORIA" global vira "NORMAL (ORTOFORIA)" — o resultado
 * normal passa a ser reconhecível de imediato e entra primeiro na lista
 * (ordenação normal-first em buildFormProps). Rename preserva a FK dos
 * prontuários existentes. Tipos custom de clínica não são tocados.
 */
return new class() extends Migration {
    public function up(): void
    {
        $exists = DB::table('cover_test_types')
            ->whereNull('entity_id')->whereNull('deleted_at')
            ->where('name', 'NORMAL (ORTOFORIA)')->exists();

        if (! $exists) {
            DB::table('cover_test_types')
                ->whereNull('entity_id')
                ->whereIn('name', ['ORTOFORIA', 'Ortoforia'])
                ->update(['name' => 'NORMAL (ORTOFORIA)', 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('cover_test_types')
            ->whereNull('entity_id')->where('name', 'NORMAL (ORTOFORIA)')
            ->update(['name' => 'ORTOFORIA', 'updated_at' => now()]);
    }
};
