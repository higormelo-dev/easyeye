<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Acuidade visual "Conta Dedos" COM distância (requisito clínico):
 * cria CD 1M..CD 5M no catálogo GLOBAL (scales 23..27), renumera
 * VULTOS/PL/SPL (28/29/30) e DESATIVA o antigo "CONTA DEDOS" sem distância —
 * prontuários antigos que apontam pra ele continuam íntegros (FK preservada,
 * nome histórico exibido); novos registros usam sempre CD Xm.
 */
return new class() extends Migration {
    public function up(): void
    {
        // Renumera qualitativos pra abrir espaço (23..27 = CD 1M..CD 5M)
        foreach (['VULTOS' => 28, 'PL' => 29, 'SPL' => 30] as $name => $scale) {
            DB::table('visual_acuity_types')
                ->whereNull('entity_id')->where('name', $name)
                ->update(['scale' => $scale, 'updated_at' => now()]);
        }

        foreach ([23 => 'CD 1M', 24 => 'CD 2M', 25 => 'CD 3M', 26 => 'CD 4M', 27 => 'CD 5M'] as $scale => $name) {
            $existing = DB::table('visual_acuity_types')
                ->whereNull('entity_id')->whereNull('deleted_at')
                ->where('name', $name)->first(['id']);

            if ($existing) {
                DB::table('visual_acuity_types')->where('id', $existing->id)
                    ->update(['scale' => $scale, 'active' => true, 'updated_at' => now()]);

                continue;
            }

            DB::table('visual_acuity_types')->insert([
                'id'         => (string) Str::uuid7(),
                'entity_id'  => null,
                'code'       => 'VATP-' . strtoupper(Str::random(8)),
                'name'       => $name,
                'scale'      => $scale,
                'active'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Antigo "CONTA DEDOS" (sem distância) sai do dropdown; histórico fica.
        DB::table('visual_acuity_types')
            ->whereNull('entity_id')->where('name', 'CONTA DEDOS')
            ->update(['active' => false, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('visual_acuity_types')
            ->whereNull('entity_id')->where('name', 'CONTA DEDOS')
            ->update(['active' => true, 'updated_at' => now()]);

        DB::table('visual_acuity_types')
            ->whereNull('entity_id')->whereIn('name', ['CD 1M', 'CD 2M', 'CD 3M', 'CD 4M', 'CD 5M'])
            ->update(['active' => false, 'updated_at' => now()]);

        foreach (['VULTOS' => 24, 'PL' => 25, 'SPL' => 26] as $name => $scale) {
            DB::table('visual_acuity_types')
                ->whereNull('entity_id')->where('name', $name)
                ->update(['scale' => $scale, 'updated_at' => now()]);
        }
    }
};
