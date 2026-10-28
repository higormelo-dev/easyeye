<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MedicineSource;
use App\Models\Medicine;

/**
 * Formata linhas de prescrição médica a partir do model Medicine.
 *
 * F5 — paridade smart_oftal `addMedicine` (jQuery legacy) reescrito server-side.
 * Garante consistência da prescrição clínica + permite evolução isolada de
 * formato (ex: tradução, layout específico do laudo) sem mexer no front.
 *
 * Convenção de saída (paridade visual):
 *   "- {nome} ({apresentação})\n  {dosagem} {frequência} por {duração}\n  Obs: {instruções}\n\n"
 *
 * Limite de 5 medicamentos por prescrição é regra clínica; não é validação técnica.
 * Ela vive na UI Alpine e impede append local. Backend não bloqueia (caso
 * exista futura demanda regulatória diferente).
 */
class MedicationPrescriptionService
{
    public const MAX_MEDICINES = 5;

    /**
     * $posologyOverride: posologia confirmada/editada pelo médico no modal
     * (fluxo "sugerir → confirmar/editar → adicionar") — quando presente,
     * substitui dosagem/frequência/duração/instruções da base; cada linha do
     * texto entra indentada sob o cabeçalho do medicamento.
     */
    public function formatLine(Medicine $medicine, ?string $posologyOverride = null): string
    {
        $line = $this->headLine($medicine);

        $override = trim((string) $posologyOverride);

        if ($override !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $override) as $overrideLine) {
                $overrideLine = trim($overrideLine);

                if ($overrideLine !== '') {
                    $line .= "\n  " . $overrideLine;
                }
            }

            return $line . "\n\n";
        }

        $dosage       = trim((string) $medicine->dosage);
        $frequency    = trim((string) $medicine->frequency);
        $duration     = trim((string) $medicine->duration);
        $instructions = trim((string) $medicine->instructions);

        $usage = trim($dosage . ' ' . $frequency);

        if ($duration !== '') {
            $usage = trim($usage . ' por ' . $duration);
        }

        if ($usage !== '') {
            $line .= "\n  " . $usage;
        }

        if ($instructions !== '') {
            $line .= "\n  Obs: " . $instructions;
        }

        return $line . "\n\n";
    }

    /**
     * Cabeçalho do item na receita. Curado/da clínica: "- NOME (Apresentação)".
     * Importado da CMED: "- PRED 1,2 MG/ML suspensão oftálmica (acetato de
     * prednisolona)" — concentração e forma vêm da apresentação da Anvisa e
     * o genérico entra entre parênteses quando o nome é comercial.
     */
    private function headLine(Medicine $medicine): string
    {
        $name = trim((string) $medicine->name);

        if ($medicine->source === MedicineSource::Cmed) {
            $head       = trim('- ' . $name . ' ' . $medicine->concentration . ' ' . $medicine->formLabel());
            $ingredient = trim((string) $medicine->active_ingredient);

            if ($ingredient !== '' && Medicine::normalizeSearch($ingredient) !== Medicine::normalizeSearch($name)) {
                $head .= ' (' . $ingredient . ')';
            }

            return (string) preg_replace('/\s+/', ' ', $head);
        }

        $medicine->loadMissing('presentation');
        $presentation = trim((string) $medicine->presentation?->name);

        return '- ' . $name . ($presentation !== '' ? ' (' . $presentation . ')' : '');
    }

    /**
     * Concatena múltiplas linhas. Caller passa lista ordenada de Medicines.
     *
     * @param iterable<Medicine> $medicines
     */
    public function formatPrescription(iterable $medicines): string
    {
        $out = '';

        foreach ($medicines as $medicine) {
            $out .= $this->formatLine($medicine);
        }

        return rtrim($out) . "\n";
    }
}
