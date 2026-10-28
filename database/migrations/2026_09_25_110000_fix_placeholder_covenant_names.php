<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige convênios gravados com nome "******" — placeholder da base aberta
 * da ANS para "sem nome fantasia" (ex.: ANS 423891, BENEFIT BENEFICIOS LTDA),
 * que o CovenantsSeeder copiava como nome. Usa a razão social, em maiúsculas
 * como o model grava (HasUppercaseFields). O seeder já foi corrigido, mas o
 * firstOrCreate dele não atualiza linhas existentes — daí esta migration.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::table('covenants')
            ->where('name', 'like', '%*%')
            ->whereNotNull('company_name')
            ->get(['id', 'name', 'company_name'])
            ->filter(fn ($c) => preg_match('/^\*+$/', trim($c->name)) === 1)
            ->each(fn ($c) => DB::table('covenants')->where('id', $c->id)->update([
                'name'       => mb_strtoupper(trim($c->company_name), 'UTF-8'),
                'updated_at' => now(),
            ]));
    }

    /** Sem volta: restaurar o placeholder "******" não tem valor. */
    public function down(): void
    {
    }
};
