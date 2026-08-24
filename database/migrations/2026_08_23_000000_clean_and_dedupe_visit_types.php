<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Limpeza dos "Tipos de consulta" GLOBAIS (entity_id null):
 *
 *  1. DEDUPE — o VisitTypesSeeder usava updateOrCreate por nome em Title Case,
 *     mas o model tem HasUppercaseName (salva MAIÚSCULO): a busca nunca achava
 *     e cada rodada do seeder criava o catálogo inteiro de novo (3 cópias em
 *     produção). Sobrevive a linha mais antiga; schedules.visit_id e
 *     waiting_list.visit_id das duplicatas são re-apontados antes de apagar.
 *  2. RENAME — nomenclatura enxuta do ticket (CONSULTA MÉDICA→CONSULTA etc.),
 *     preservando FKs; se o destino já existir, faz merge (re-point + delete).
 *  3. DESATIVA os tipos fora da rotina clínica (procedimentos, óptica,
 *     administrativo...) — desativar, não apagar: agendamentos antigos mantêm
 *     o vínculo, e a clínica pode reativar em Configurações se usar.
 *  4. GARANTE a lista inicial do ticket ativa (cria SEGUNDA OPINIÃO).
 *  5. TRAVA DE BANCO — unique parcial no nome global: mesmo que algum seeder
 *     volte a rodar torto, o banco recusa a duplicata (raiz do problema
 *     morre aqui, não só na limpeza).
 *
 * Tipos criados pelas clínicas (entity_id preenchido) não são tocados.
 */
return new class() extends Migration {
    public function up(): void
    {
        // ── 1. Dedupe globais por nome ───────────────────────────────────
        $globals = DB::table('visit_types')
            ->whereNull('entity_id')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'name']);

        $survivors = [];

        foreach ($globals as $row) {
            $key = mb_strtoupper(trim($row->name), 'UTF-8');

            if (! isset($survivors[$key])) {
                $survivors[$key] = $row->id;

                continue;
            }

            $this->mergeInto($row->id, $survivors[$key]);
        }

        // ── 2. Renames (nomenclatura enxuta), com merge se destino existir ─
        $renames = [
            'CONSULTA MÉDICA'                 => 'CONSULTA',
            'CONSULTA DE RETORNO'             => 'RETORNO',
            'CONSULTA DE URGÊNCIA/EMERGÊNCIA' => 'URGÊNCIA',
            'CONSULTA PRÉ-OPERATÓRIA'         => 'AVALIAÇÃO PRÉ-OPERATÓRIA',
            'CONSULTA PÓS-OPERATÓRIA'         => 'AVALIAÇÃO PÓS-OPERATÓRIA',
        ];

        foreach ($renames as $from => $to) {
            $source = $survivors[$from] ?? null;

            if ($source === null) {
                continue;
            }

            if (isset($survivors[$to])) {
                $this->mergeInto($source, $survivors[$to]);
                unset($survivors[$from]);

                continue;
            }

            DB::table('visit_types')->where('id', $source)
                ->update(['name' => $to, 'updated_at' => now()]);
            $survivors[$to] = $source;
            unset($survivors[$from]);
        }

        // ── 3. Desativa os fora da rotina clínica ─────────────────────────
        $deactivate = [
            'EXAME OFTALMOLÓGICO', 'EXAME COMPLEMENTAR', 'MAPEAMENTO DE RETINA',
            'PROCEDIMENTO AMBULATORIAL', 'PROCEDIMENTO TERAPÊUTICO',
            'AVALIAÇÃO TÉCNICA/ENFERMAGEM', 'AVALIAÇÃO PARA ÓCULOS OU LENTES',
            'ATENDIMENTO DE ÓPTICA', 'ATENDIMENTO ADMINISTRATIVO',
        ];

        DB::table('visit_types')
            ->whereNull('entity_id')
            ->whereIn('name', $deactivate)
            ->update(['active' => false, 'updated_at' => now()]);

        // ── 4. Lista inicial do ticket sempre presente e ativa ────────────
        $keep = [
            'CONSULTA', 'RETORNO', 'URGÊNCIA', 'AVALIAÇÃO PRÉ-OPERATÓRIA',
            'AVALIAÇÃO PÓS-OPERATÓRIA', 'SEGUNDA OPINIÃO', 'TELECONSULTA', 'TRIAGEM',
        ];

        foreach ($keep as $name) {
            $existing = DB::table('visit_types')
                ->whereNull('entity_id')->whereNull('deleted_at')
                ->where('name', $name)->first(['id']);

            if ($existing) {
                DB::table('visit_types')->where('id', $existing->id)
                    ->update(['active' => true, 'updated_at' => now()]);

                continue;
            }

            DB::table('visit_types')->insert([
                'id'         => (string) Str::uuid7(),
                'entity_id'  => null,
                'code'       => 'VTP-' . strtoupper(Str::random(8)),
                'name'       => $name,
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // ── 5. Trava anti-recorrência: unique parcial no nome global ─────
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS visit_types_global_name_unique
            ON visit_types (name)
            WHERE entity_id IS NULL AND deleted_at IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS visit_types_global_name_unique');
        // Dedupe/renames não são reversíveis (dados consolidados).
    }

    /** Re-aponta FKs do duplicado pro sobrevivente e apaga o duplicado. */
    private function mergeInto(string $duplicateId, string $survivorId): void
    {
        DB::table('schedules')->where('visit_id', $duplicateId)->update(['visit_id' => $survivorId]);
        DB::table('waiting_list')->where('visit_id', $duplicateId)->update(['visit_id' => $survivorId]);
        DB::table('visit_types')->where('id', $duplicateId)->delete();
    }
};
