<?php

declare(strict_types=1);

namespace App\Services\Covenants;

use App\Enums\ImportStatus;
use App\Jobs\ProcessPatientImportJob;
use App\Models\{PatientImport, User};
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Bus, DB, Log, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Reprocessa só as linhas que falharam por convênio: cria um import FILHO (cópia do CSV original + `reprocess_lines`)
 * e o enfileira; o import original fica imutável. A não-duplicação de pacientes vem da deduplicação do
 * PatientImportService (CPF, nome+telefone, import_code) somada ao filtro de linhas — reprocessar não relê o arquivo todo.
 */
class PatientImportReprocessService
{
    public function __construct(
        private readonly PatientImportCovenantReportService $report,
        private readonly PatientImportCovenantResolver $resolver,
    ) {
    }

    /**
     * @throws ValidationException   não terminou, arquivo expirado/ausente, relatório indisponível, sem linhas de convênio (422)
     * @throws ConflictHttpException já há import ativo na clínica ou reprocessamento ativo deste import (409)
     */
    public function reprocess(PatientImport $parent, User $user): PatientImport
    {
        $entityId = (string) $parent->entity_id;
        $lines    = $this->retryPlan($parent);
        $path     = "imports/patients/{$entityId}/" . Str::uuid() . '.csv';

        try {
            $child = DB::transaction(function () use ($parent, $user, $entityId, $lines, $path): PatientImport {
                // Serializa dois pedidos para o mesmo pai; o índice único parcial é a última barreira.
                $locked = PatientImport::withoutGlobalScopes()->whereKey($parent->id)->lockForUpdate()->firstOrFail();

                $active = PatientImport::withoutGlobalScopes()->where('entity_id', $entityId)
                    ->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])->exists();

                if ($active) {
                    throw new ConflictHttpException(__('imports.patients.covenant_mapping.reprocess_running'));
                }

                Storage::disk()->copy((string) $locked->file_path, $path);

                $child = PatientImport::create([
                    'entity_id'                 => $entityId,
                    'user_id'                   => $user->id,
                    'status'                    => ImportStatus::Pending,
                    'file_path'                 => $path,
                    'original_name'             => $locked->original_name,
                    'reprocess_of_id'           => $locked->id,
                    'reprocess_lines'           => $lines['lines'],
                    'total_rows'                => count($lines['lines']),
                    'confirmed_at'              => now(),
                    'files_expire_at'           => now()->addDays((int) config('patient_imports.retention_days', 30)),
                    'covenant_mapping_snapshot' => $lines['snapshot'],
                    'preview'                   => [
                        'reprocess'        => ['parent_id' => (string) $locked->id, 'lines' => count($lines['lines'])],
                        'covenant_summary' => $lines['summary'],
                    ],
                ]);

                // Trilha do reprocessamento (quem, pai, filho = auditable, nº de linhas) na MESMA transação do filho: sem filho,
                // sem registro. Só ids e contagem — nenhum dado de paciente.
                app(AuditService::class)->log('reprocessed', $child, null, [
                    'reprocess_of_id' => (string) $locked->id,
                    'user_id'         => (string) $user->id,
                    'lines'           => count($lines['lines']),
                ]);

                return $child;
            });
        } catch (Throwable $e) {
            Storage::disk()->delete($path); // a cópia não fica órfã

            if ($e instanceof UniqueConstraintViolationException) {
                throw new ConflictHttpException(__('imports.patients.covenant_mapping.reprocess_running'), $e);
            }

            throw $e;
        }

        // Só depois do commit: o job nunca enxerga um filho que ainda pode ser desfeito.
        try {
            Bus::dispatch(new ProcessPatientImportJob($child));
        } catch (Throwable $e) {
            $child->update(['status' => ImportStatus::Failed, 'abort_reason' => __('shared_identity.import.failed'), 'finished_at' => now()]);
            Log::error('patient_import.reprocess_dispatch_failed', ['import_id' => (string) $child->id, 'exception' => $e::class]);

            throw $e; // o arquivo fica para uma nova tentativa
        }

        return $child;
    }

    /**
     * Pré-condições + o que o filho precisa: linhas, snapshot do de-para dos nomes que falharam e o resumo da prévia.
     *
     * @return array{lines: list<int>, snapshot: array<string, mixed>, summary: array<string, mixed>}
     */
    private function retryPlan(PatientImport $parent): array
    {
        if (! in_array($parent->status, [ImportStatus::Done, ImportStatus::Failed], true)) {
            throw $this->unprocessable('not_finished');
        }

        if ($this->report->filesExpired($parent)) {
            throw $this->unprocessable('expired');
        }

        if (! $this->report->originalAvailable($parent)) {
            throw $this->unprocessable('file_missing');
        }

        $analysis = $this->report->analysis($parent);

        if ($analysis['retry_lines'] === []) {
            // Import antigo sem CSV de erros: não há como saber quais linhas falharam.
            throw $this->unprocessable($parent->covenant_error_lines === null && ! $this->report->errorsFileAvailable($parent) ? 'report_unavailable' : 'no_retry_lines');
        }

        $failed = array_values(array_filter($analysis['items'], fn (array $item): bool => $item['failed_rows'] > 0));

        $this->resolver->load((string) $parent->entity_id);

        return [
            'lines'    => $analysis['retry_lines'],
            'snapshot' => $this->resolver->snapshot(array_column($failed, 'source_name')),
            'summary'  => PatientImportCovenantReportService::summaryPayload(
                array_map(fn (array $item): array => [...$item, 'rows' => $item['failed_rows']], $failed),
                count($analysis['retry_lines']),
            ),
        ];
    }

    private function unprocessable(string $reason): ValidationException
    {
        return ValidationException::withMessages(['reprocess' => [__("imports.patients.covenant_mapping.{$reason}")]]);
    }
}
