<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Models\AiDoctorPrompt;
use App\Models\Doctor;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gerencia os prompts favoritos do médico (Onda 3, P1). Limite hard de 5 por
 * médico — enforced no save. Cross-tenant guard via entity_id em todas as
 * operações.
 */
class AiDoctorPromptService
{
    public const MAX_PROMPTS_PER_DOCTOR = 5;

    /**
     * Resolve o Doctor associado ao user logado na entity ativa. Retorna null
     * para admin/secretary sem perfil médico (UI esconde o bloco).
     */
    public function resolveDoctor(string $entityId, string $userId): ?Doctor
    {
        return Doctor::query()
            ->whereHas('entityUser', function ($q) use ($entityId, $userId): void {
                $q->where('entity_id', $entityId)->where('user_id', $userId);
            })
            ->first();
    }

    /**
     * @return Collection<int, AiDoctorPrompt>
     */
    public function listForDoctor(string $doctorId, string $entityId): Collection
    {
        return AiDoctorPrompt::query()
            ->where('doctor_id', $doctorId)
            ->where('entity_id', $entityId)
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Cria um prompt. Falha com DomainException quando o médico já atingiu o
     * limite de 5 favoritos.
     */
    public function create(string $doctorId, string $entityId, string $label, string $prompt): AiDoctorPrompt
    {
        return DB::transaction(function () use ($doctorId, $entityId, $label, $prompt): AiDoctorPrompt {
            // Lock for update por linha (Postgres não aceita FOR UPDATE com aggregate).
            $existing = AiDoctorPrompt::query()
                ->where('doctor_id', $doctorId)
                ->lockForUpdate()
                ->get(['id']);

            if ($existing->count() >= self::MAX_PROMPTS_PER_DOCTOR) {
                throw new DomainException('limit_reached');
            }

            return AiDoctorPrompt::query()->create([
                'doctor_id' => $doctorId,
                'entity_id' => $entityId,
                'label'     => mb_substr($label, 0, 120),
                'prompt'    => $prompt,
                'position'  => $existing->count(),
            ]);
        });
    }

    public function update(AiDoctorPrompt $prompt, string $label, string $promptText): AiDoctorPrompt
    {
        $prompt->update([
            'label'  => mb_substr($label, 0, 120),
            'prompt' => $promptText,
        ]);

        return $prompt->fresh();
    }

    public function destroy(AiDoctorPrompt $prompt): void
    {
        DB::transaction(function () use ($prompt): void {
            $doctorId = (string) $prompt->doctor_id;
            $prompt->delete();

            // Compacta as posições para evitar "buracos" — facilita reorder na UI.
            AiDoctorPrompt::query()
                ->where('doctor_id', $doctorId)
                ->orderBy('position')
                ->get(['id'])
                ->each(function (AiDoctorPrompt $p, int $i): void {
                    $p->update(['position' => $i]);
                });
        });
    }

    /**
     * @param array<int, string> $orderedIds
     */
    public function reorder(string $doctorId, array $orderedIds): void
    {
        DB::transaction(function () use ($doctorId, $orderedIds): void {
            foreach ($orderedIds as $position => $id) {
                AiDoctorPrompt::query()
                    ->where('id', $id)
                    ->where('doctor_id', $doctorId)
                    ->update(['position' => $position]);
            }
        });
    }
}
