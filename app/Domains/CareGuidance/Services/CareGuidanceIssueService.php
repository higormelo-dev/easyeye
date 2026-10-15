<?php

declare(strict_types=1);

namespace App\Domains\CareGuidance\Services;

use App\Domains\CareGuidance\Exceptions\CareGuidanceException as Refused;
use App\Domains\CareGuidance\Models\{CareGuidanceAttachment, CareGuidanceIssue, CareGuidanceTemplate};
use App\Enums\MedicalRecordProcedureStatus;
use App\Models\{Doctor, MedicalRecordProcedure};
use App\Models\Scopes\EntityScope;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Throwable;

/**
 * Emissão da orientação pós-procedimento (Fase 6 / S14): do modelo da clínica ao que o paciente recebe. O texto, os
 * colírios e o local podem ser ajustados para ESTE paciente no ato; o resultado é um retrato CONGELADO
 * (CareGuidanceIssue): título, texto, local, esquema de colírios, olho do procedimento e uma CÓPIA dos PDFs, com o hash
 * do conteúdo. Mudar ou apagar o modelo depois não muda o que foi entregue.
 *
 * Clique duplo é inofensivo: a mesma orientação (mesmo conteúdo) do mesmo procedimento nos últimos minutos devolve a
 * emissão que já existe, sem duplicar o registro nem os arquivos.
 */
final class CareGuidanceIssueService
{
    /** Janela em que a mesma emissão (mesmo conteúdo) conta como a mesma. */
    public const DEDUPE_MINUTES = 10;

    public const MAX_BODY = 8000;

    private const HASH_DOMAIN = 'care-guidance-issue';

    public function __construct(private readonly CareGuidanceTemplateService $templates)
    {
    }

    /**
     * Modelos ativos que servem ao procedimento (os dele, depois os gerais).
     *
     * @return list<array<string, mixed>>
     */
    public function templatesFor(MedicalRecordProcedure $procedure): array
    {
        return CareGuidanceTemplate::query()->withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $procedure->entity_id)
            ->where('active', true)
            ->where(static fn ($query) => $query->whereNull('procedure_id')->orWhere('procedure_id', $procedure->procedure_id))
            ->with(['attachments' => static fn ($query) => $query->withoutGlobalScopes(), 'procedure:id,name,code'])
            ->orderByRaw('CASE WHEN procedure_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('title')
            ->get()
            ->map(fn (CareGuidanceTemplate $template): array => $this->templates->serialize($template))
            ->values()
            ->all();
    }

    /**
     * @param array{body?: ?string, location_label?: ?string, eye_drops?: ?array<int, array<string, mixed>>} $overrides ajustes para este paciente
     *
     * @throws Refused
     */
    public function issue(MedicalRecordProcedure $procedure, string $templateId, ?Doctor $doctor, array $overrides = [], ?CarbonImmutable $now = null): CareGuidanceIssue
    {
        $now ??= CarbonImmutable::now();

        if ($procedure->status === MedicalRecordProcedureStatus::Cancelled) {
            throw Refused::make(Refused::PROCEDURE_CANCELLED, 'Procedimento cancelado não tem orientação.');
        }

        $template = CareGuidanceTemplate::query()->withoutGlobalScope(EntityScope::class)
            ->where('entity_id', $procedure->entity_id)
            ->with(['attachments' => static fn ($query) => $query->withoutGlobalScopes()])
            ->find($templateId);

        if ($template === null) {
            throw Refused::make(Refused::TEMPLATE_NOT_FOUND, 'Modelo não encontrado nesta clínica.', 'template_id');
        }

        if (! $template->active) {
            throw Refused::make(Refused::TEMPLATE_INACTIVE, 'O modelo está desativado.', 'template_id');
        }

        if ($template->procedure_id !== null && (string) $template->procedure_id !== (string) $procedure->procedure_id) {
            throw Refused::make(Refused::PROCEDURE_MISMATCH, 'O modelo é de outro procedimento.', 'template_id');
        }

        $body  = mb_substr(CanonicalJson::normalizeText(trim((string) ($overrides['body'] ?? $template->body))), 0, self::MAX_BODY);
        $drops = array_key_exists('eye_drops', $overrides) ? $this->templates->normalizeDrops($overrides['eye_drops']) : $this->templates->normalizeDrops($template->eye_drops ?? []);
        $place = array_key_exists('location_label', $overrides)
            ? (filled($overrides['location_label']) ? mb_substr(trim((string) $overrides['location_label']), 0, 160) : null)
            : $template->location_label;
        $eye = in_array($procedure->eye, ['right', 'left', 'both'], true) ? $procedure->eye : null;

        $files = $template->attachments->map(static fn (CareGuidanceAttachment $attachment): array => [
            'name'       => $attachment->original_name,
            'size_bytes' => (int) $attachment->size_bytes,
            'sha256'     => $attachment->sha256,
            'source'     => $attachment->path,
        ])->values()->all();

        $hash = CanonicalJson::hashOf(self::HASH_DOMAIN, [
            'title'       => $template->title,
            'body'        => $body,
            'place'       => $place,
            'eye'         => $eye,
            'drops'       => $drops,
            'attachments' => array_map(static fn (array $file): array => ['name' => $file['name'], 'sha256' => $file['sha256']], $files),
        ]);

        return DB::transaction(function () use ($procedure, $template, $doctor, $now, $body, $drops, $place, $eye, $files, $hash): CareGuidanceIssue {
            // Uma emissão por vez por procedimento: o clique duplo espera a trava e acha a emissão pronta.
            MedicalRecordProcedure::query()->withoutGlobalScopes()->whereKey($procedure->id)->lockForUpdate()->firstOrFail();

            $existing = CareGuidanceIssue::query()->withoutGlobalScopes()
                ->where('medical_record_procedure_id', $procedure->id)
                ->where('content_hash', $hash)
                ->where('issued_at', '>=', $now->subMinutes(self::DEDUPE_MINUTES))
                ->latest('issued_at')
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $issue = new CareGuidanceIssue([
                'entity_id'                   => $procedure->entity_id,
                'patient_id'                  => $procedure->patient_id,
                'medical_record_procedure_id' => $procedure->id,
                'template_id'                 => $template->id,
                'doctor_id'                   => $doctor?->id,
                'title'                       => $template->title,
                'location_label'              => $place,
                'eye'                         => $eye,
                'body'                        => $body,
                'eye_drops'                   => $drops,
                'attachments'                 => [],
                'content_hash'                => $hash,
                'issued_at'                   => $now,
            ]);
            $issue->id = (string) Str::uuid();

            // Cópia dos PDFs para a pasta da emissão: o modelo pode mudar ou sumir sem tocar no que foi entregue.
            $copies  = [];
            $written = [];

            // A limpeza é registrada ANTES da 1ª cópia e lê a lista por referência: um PDF que falta (ou uma cópia que
            // falha) no meio do laço lança daqui e desfaz a transação — registrada só depois do laço, a limpeza nunca
            // existia e as cópias anteriores ficavam no disco sem emissão, mais algumas a cada nova tentativa.
            DB::afterRollBack(static function () use (&$written): void {
                try {
                    Storage::disk()->delete($written);
                } catch (Throwable $e) {
                    // A limpeza não pode trocar o motivo da recusa por um erro de disco; o arquivo sobra, mas o erro é visto.
                    report($e);
                }
            });

            foreach ($files as $index => $file) {
                $target = "care-guidance/{$procedure->entity_id}/issues/{$issue->id}/" . ($index + 1) . '-' . substr($file['sha256'], 0, 12) . '.pdf';

                $unavailable = static fn (): Refused => Refused::make(Refused::ATTACHMENT_MISSING_FILE, 'O PDF "' . $file['name'] . '" não está mais disponível: reenvie o anexo no modelo.');

                if (! Storage::disk()->exists($file['source'])) {
                    throw $unavailable();
                }

                // O destino entra na lista antes de copiar: uma cópia que falha no meio pode deixar o arquivo pela
                // metade, e apagar o que não existe não faz nada (a pasta é nova, com o id desta emissão).
                $written[] = $target;

                if (! Storage::disk()->copy($file['source'], $target)) {
                    throw $unavailable();
                }

                $copies[] = ['name' => $file['name'], 'size_bytes' => $file['size_bytes'], 'sha256' => $file['sha256'], 'path' => $target];
            }

            $issue->attachments = $copies;
            $issue->save();

            return $issue;
        });
    }

    /**
     * As emissões do paciente (do procedimento, se informado), da mais nova para a mais antiga.
     *
     * @return list<array<string, mixed>>
     */
    public function issuesFor(string $entityId, string $patientId, ?string $procedureId = null): array
    {
        return CareGuidanceIssue::query()->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->when($procedureId !== null, static fn ($query) => $query->where('medical_record_procedure_id', $procedureId))
            ->with('doctor.person:id,full_name')
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn (CareGuidanceIssue $issue): array => $this->serializeIssue($issue))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function serializeIssue(CareGuidanceIssue $issue): array
    {
        return [
            'id'             => (string) $issue->id,
            'title'          => $issue->title,
            'location_label' => $issue->location_label,
            'eye'            => $issue->eye,
            'issued_at'      => $issue->issued_at?->toIso8601String(),
            'doctor_name'    => $issue->doctor?->person?->full_name,
            'attachments'    => collect($issue->attachments ?? [])->map(static fn (array $file, int $index): array => [
                'index'      => $index,
                'name'       => $file['name'],
                'size_bytes' => $file['size_bytes'],
            ])->values()->all(),
        ];
    }
}
