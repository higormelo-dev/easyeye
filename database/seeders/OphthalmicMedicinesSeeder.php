<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\{Medicine, MedicinePresentation};
use Illuminate\Database\Seeder;

/**
 * Base GLOBAL (entity_id null) de medicamentos de uso oftalmológico —
 * princípios ativos e apresentações padrão de bulário, com posologia USUAL
 * de adulto como SUGESTÃO inicial.
 *
 * Importante (requisito do ticket): posologia aqui NUNCA é obrigatória nem
 * única — o receituário sempre apresenta como sugestão editável e o médico
 * confirma/ajusta conforme indicação, apresentação e paciente. A clínica
 * pode complementar com itens próprios (entity_id preenchido) via catálogo.
 *
 * Idempotente: updateOrCreate por (entity_id null + name).
 */
class OphthalmicMedicinesSeeder extends Seeder
{
    public function run(): void
    {
        $presentations = [];

        foreach (['Colírio', 'Suspensão oftálmica', 'Gel oftálmico', 'Pomada oftálmica', 'Comprimido', 'Cápsula'] as $name) {
            $presentations[$name] = MedicinePresentation::updateOrCreate(
                ['entity_id' => null, 'name' => $name],
                ['active' => true],
            )->id;
        }

        // [nome, apresentação, dosagem, frequência, duração, instruções]
        $medicines = [
            // ── Antibióticos tópicos ────────────────────────────────────────
            ['TOBRAMICINA 0,3%', 'Colírio', '1 gota', 'de 4/4h', '7 dias', 'No(s) olho(s) afetado(s).'],
            ['MOXIFLOXACINO 0,5%', 'Colírio', '1 gota', 'de 8/8h', '7 dias', 'No(s) olho(s) afetado(s).'],
            ['CIPROFLOXACINO 0,35%', 'Colírio', '1 gota', 'de 4/4h', '7 dias', 'No(s) olho(s) afetado(s).'],
            ['OFLOXACINO 0,3%', 'Colírio', '1 gota', 'de 6/6h', '7 dias', 'No(s) olho(s) afetado(s).'],
            ['GATIFLOXACINO 0,5%', 'Colírio', '1 gota', 'de 8/8h', '7 dias', 'No(s) olho(s) afetado(s).'],
            ['AZITROMICINA 1,5%', 'Colírio', '1 gota', 'de 12/12h', '3 dias', 'No(s) olho(s) afetado(s).'],
            ['TOBRAMICINA POMADA 0,3%', 'Pomada oftálmica', 'Aplicar fina camada', 'de 8/8h', '7 dias', 'No fundo de saco conjuntival.'],

            // ── Corticoides tópicos ─────────────────────────────────────────
            ['PREDNISOLONA 1%', 'Suspensão oftálmica', '1 gota', 'de 6/6h', '7 dias', 'Agitar antes de usar. Reduzir gradualmente conforme orientação.'],
            ['DEXAMETASONA 0,1%', 'Colírio', '1 gota', 'de 6/6h', '7 dias', 'Reduzir gradualmente conforme orientação.'],
            ['LOTEPREDNOL 0,5%', 'Suspensão oftálmica', '1 gota', 'de 6/6h', '14 dias', 'Agitar antes de usar.'],
            ['FLUORMETOLONA 0,1%', 'Suspensão oftálmica', '1 gota', 'de 6/6h', '14 dias', 'Agitar antes de usar.'],

            // ── Associações antibiótico + corticoide ────────────────────────
            ['TOBRAMICINA + DEXAMETASONA', 'Suspensão oftálmica', '1 gota', 'de 6/6h', '7 dias', 'Agitar antes de usar. No(s) olho(s) afetado(s).'],
            ['MOXIFLOXACINO + DEXAMETASONA', 'Colírio', '1 gota', 'de 6/6h', '7 dias', 'No(s) olho(s) afetado(s).'],
            ['CIPROFLOXACINO + DEXAMETASONA', 'Suspensão oftálmica', '1 gota', 'de 6/6h', '7 dias', 'Agitar antes de usar.'],
            ['NEOMICINA + POLIMIXINA B + DEXAMETASONA', 'Colírio', '1 gota', 'de 6/6h', '7 dias', 'No(s) olho(s) afetado(s).'],

            // ── Antiglaucomatosos ───────────────────────────────────────────
            ['TIMOLOL 0,5%', 'Colírio', '1 gota', 'de 12/12h', 'uso contínuo', 'Em ambos os olhos, salvo orientação contrária.'],
            ['LATANOPROSTA 0,005%', 'Colírio', '1 gota', '1x à noite', 'uso contínuo', 'Aplicar sempre no mesmo horário.'],
            ['TRAVOPROSTA 0,004%', 'Colírio', '1 gota', '1x à noite', 'uso contínuo', 'Aplicar sempre no mesmo horário.'],
            ['BIMATOPROSTA 0,03%', 'Colírio', '1 gota', '1x à noite', 'uso contínuo', 'Aplicar sempre no mesmo horário.'],
            ['BRIMONIDINA 0,2%', 'Colírio', '1 gota', 'de 12/12h', 'uso contínuo', ''],
            ['DORZOLAMIDA 2%', 'Colírio', '1 gota', 'de 8/8h', 'uso contínuo', ''],
            ['BRINZOLAMIDA 1%', 'Suspensão oftálmica', '1 gota', 'de 12/12h', 'uso contínuo', 'Agitar antes de usar.'],
            ['TIMOLOL + DORZOLAMIDA', 'Colírio', '1 gota', 'de 12/12h', 'uso contínuo', ''],
            ['TIMOLOL + BRIMONIDINA', 'Colírio', '1 gota', 'de 12/12h', 'uso contínuo', ''],
            ['LATANOPROSTA + TIMOLOL', 'Colírio', '1 gota', '1x à noite', 'uso contínuo', 'Aplicar sempre no mesmo horário.'],
            ['PILOCARPINA 2%', 'Colírio', '1 gota', 'de 8/8h', 'conforme orientação', ''],

            // ── Lubrificantes / lágrimas artificiais ────────────────────────
            ['HIALURONATO DE SÓDIO 0,15%', 'Colírio', '1 gota', 'de 6/6h ou conforme necessidade', 'uso contínuo', 'Pode ser usado com lentes de contato, conforme apresentação.'],
            ['HIALURONATO DE SÓDIO 0,3%', 'Colírio', '1 gota', 'de 6/6h ou conforme necessidade', 'uso contínuo', ''],
            ['CARMELOSE 0,5%', 'Colírio', '1 gota', 'de 6/6h ou conforme necessidade', 'uso contínuo', ''],
            ['CARBOXIMETILCELULOSE 1%', 'Colírio', '1 gota', 'de 6/6h ou conforme necessidade', 'uso contínuo', ''],
            ['TREALOSE + HIALURONATO', 'Colírio', '1 gota', 'de 6/6h ou conforme necessidade', 'uso contínuo', ''],
            ['DEXPANTENOL GEL 5%', 'Gel oftálmico', 'Aplicar 1 cm', 'à noite, ao deitar', 'uso contínuo', ''],

            // ── Antialérgicos ───────────────────────────────────────────────
            ['OLOPATADINA 0,2%', 'Colírio', '1 gota', '1x ao dia', '30 dias', ''],
            ['OLOPATADINA 0,1%', 'Colírio', '1 gota', 'de 12/12h', '30 dias', ''],
            ['CETOTIFENO 0,025%', 'Colírio', '1 gota', 'de 12/12h', '30 dias', ''],
            ['ALCAFTADINA 0,25%', 'Colírio', '1 gota', '1x ao dia', '30 dias', ''],
            ['EPINASTINA 0,05%', 'Colírio', '1 gota', 'de 12/12h', '30 dias', ''],

            // ── Anti-inflamatórios não hormonais ────────────────────────────
            ['NEPAFENACO 0,1%', 'Suspensão oftálmica', '1 gota', 'de 8/8h', '14 dias', 'Agitar antes de usar.'],
            ['CETOROLACO 0,5%', 'Colírio', '1 gota', 'de 8/8h', '14 dias', ''],
            ['DICLOFENACO 0,1%', 'Colírio', '1 gota', 'de 8/8h', '14 dias', ''],

            // ── Midriáticos / cicloplégicos ─────────────────────────────────
            ['TROPICAMIDA 1%', 'Colírio', '1 gota', 'conforme orientação', 'exame', 'Visão embaçada temporária — evitar dirigir após a aplicação.'],
            ['CICLOPENTOLATO 1%', 'Colírio', '1 gota', 'conforme orientação', 'exame', 'Visão embaçada temporária — evitar dirigir após a aplicação.'],
            ['FENILEFRINA 10%', 'Colírio', '1 gota', 'conforme orientação', 'exame', ''],
            ['ATROPINA 1%', 'Colírio', '1 gota', 'conforme orientação', 'conforme orientação', ''],

            // ── Pomadas / cicatrizantes ─────────────────────────────────────
            ['RETINOL + AMINOÁCIDOS + METIONINA + CLORANFENICOL', 'Pomada oftálmica', 'Aplicar fina camada', 'à noite, ao deitar', '7 dias', 'No fundo de saco conjuntival.'],
            ['ACICLOVIR POMADA 3%', 'Pomada oftálmica', 'Aplicar 1 cm', '5x ao dia', 'até 3 dias após cicatrização', 'No fundo de saco conjuntival.'],
            ['GANCICLOVIR GEL 0,15%', 'Gel oftálmico', '1 gota', '5x ao dia', 'até cicatrização, depois 3x ao dia por 7 dias', ''],

            // ── Orais de uso frequente em oftalmologia ──────────────────────
            ['ACICLOVIR 400MG', 'Comprimido', '1 comprimido', '5x ao dia', '7 dias', 'Tomar com água, com ou sem alimentos.'],
            ['VALACICLOVIR 500MG', 'Comprimido', '1 comprimido', 'de 8/8h', '7 dias', ''],
            ['PREDNISONA 20MG', 'Comprimido', 'Conforme prescrição', '1x ao dia, pela manhã', 'conforme orientação', 'Tomar após o café da manhã. Não interromper abruptamente.'],
            ['ACETAZOLAMIDA 250MG', 'Comprimido', '1 comprimido', 'de 6/6h', 'conforme orientação', 'Ingerir bastante líquido.'],
            ['DOXICICLINA 100MG', 'Comprimido', '1 comprimido', 'de 12/12h', '15 dias', 'Tomar com bastante água; evitar deitar em seguida.'],
        ];

        foreach ($medicines as [$name, $presentation, $dosage, $frequency, $duration, $instructions]) {
            Medicine::withoutGlobalScopes()->updateOrCreate(
                ['entity_id' => null, 'name' => $name],
                [
                    'medicine_presentation_id' => $presentations[$presentation],
                    'dosage'                   => $dosage,
                    'frequency'                => $frequency,
                    'duration'                 => $duration,
                    'instructions'             => $instructions,
                    'active'                   => true,
                ],
            );
        }

        $this->command?->info('Base oftalmológica: ' . count($medicines) . ' medicamentos (globais) prontos.');
    }
}
