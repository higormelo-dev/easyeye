<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DoctorReportPhrase;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Biblioteca de frases rápidas do médico pro laudo do Gerenciador de
 * Imagens — mesmo desenho de App\Domains\AI\Services\AiDoctorPromptService
 * (label + texto + posição, por médico, limite hard no create). Limite bem
 * mais folgado que os 5 prompts de IA (30) porque frases de laudo tendem a
 * ser mais numerosas e menos "escolha cuidadosa" que um prompt de IA.
 */
class DoctorReportPhraseService
{
    public const MAX_PHRASES_PER_DOCTOR = 30;

    /**
     * @return Collection<int, DoctorReportPhrase>
     */
    public function listForDoctor(string $doctorId, string $entityId): Collection
    {
        return DoctorReportPhrase::query()
            ->where('doctor_id', $doctorId)
            ->where('entity_id', $entityId)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Cria uma frase. Falha com DomainException quando o médico já atingiu
     * o limite.
     */
    public function create(string $doctorId, string $entityId, string $label, string $content): DoctorReportPhrase
    {
        return DB::transaction(function () use ($doctorId, $entityId, $label, $content): DoctorReportPhrase {
            // Lock for update por linha (Postgres não aceita FOR UPDATE com aggregate).
            $existing = DoctorReportPhrase::query()
                ->where('doctor_id', $doctorId)
                ->lockForUpdate()
                ->get(['id']);

            if ($existing->count() >= self::MAX_PHRASES_PER_DOCTOR) {
                throw new DomainException('limit_reached');
            }

            return DoctorReportPhrase::query()->create([
                'doctor_id' => $doctorId,
                'entity_id' => $entityId,
                'label'     => mb_substr($label, 0, 120),
                'content'   => $content,
                'position'  => $existing->count(),
            ]);
        });
    }

    public function update(DoctorReportPhrase $phrase, string $label, string $content): DoctorReportPhrase
    {
        $phrase->update([
            'label'   => mb_substr($label, 0, 120),
            'content' => $content,
        ]);

        return $phrase->fresh();
    }

    public function destroy(DoctorReportPhrase $phrase): void
    {
        DB::transaction(function () use ($phrase): void {
            $doctorId = (string) $phrase->doctor_id;
            $phrase->delete();

            // Compacta as posições pra evitar "buracos".
            DoctorReportPhrase::query()
                ->where('doctor_id', $doctorId)
                ->orderBy('position')
                ->get(['id'])
                ->each(function (DoctorReportPhrase $p, int $i): void {
                    $p->update(['position' => $i]);
                });
        });
    }
}
