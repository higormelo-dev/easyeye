<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ExamSource;
use App\Models\{Patient, PatientExam};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Importação manual de exame externo (upload avulso, sem integrador) no
 * Gerenciador de Imagens. Cria uma linha de PatientExam por arquivo enviado
 * — archive é string NOT NULL, então "um exame com N arquivos" vira N
 * registros compartilhando o mesmo import_batch_id (agrupamento visual no
 * front, sem tabela própria de "lote").
 *
 * Mesmo padrão de storage sensível (LGPD art. 11) de
 * PatientExamService::storeArchive(): disco s3, visibilidade private,
 * diretório "{entity}/{patient}/exams", nome "{time()}_{uuid}.{ext}". Lido
 * depois pelas MESMAS rotas de EyeImagesController (patientExamUrls/imageUrl)
 * sem qualquer alteração — basta o archive apontar pro path certo.
 */
class ExternalExamImportService
{
    public function __construct(
        private readonly DiagnosisCatalogService $diagnosisCatalog,
    ) {
    }

    /**
     * @param array<string, mixed>     $data   Payload já validado de ImportExternalExamRequest.
     * @param array<int, UploadedFile> $files
     * @param string                   $userId Ator autenticado. Não referenciado diretamente aqui:
     *                                         created_by/updated_by já são resolvidos automaticamente
     *                                         por HasAuditColumns via AuditContext::userId() (auth()->id()).
     *                                         Mantido na assinatura por paridade com o restante dos
     *                                         services de escrita (ex.: DiagnosisCatalogService::findOrCreateCustom)
     *                                         e para uso futuro caso o import passe a rodar fora de
     *                                         um request autenticado (ex.: job assíncrono).
     *
     * @return Collection<int, PatientExam>
     *
     * @throws Throwable
     */
    public function import(string $entityId, array $data, array $files, string $userId): Collection
    {
        if (empty($files)) {
            throw ValidationException::withMessages([
                'files' => 'Envie ao menos um arquivo.',
            ]);
        }

        return DB::transaction(function () use ($entityId, $data, $files): Collection {
            // Nunca confia no patient_id cru do form — reescopa por entity_id
            // aqui dentro da transação (defesa em profundidade além do Request).
            $patient = Patient::query()
                ->where('id', $data['patient_id'])
                ->where('entity_id', $entityId)
                ->first();

            if (! $patient) {
                throw ValidationException::withMessages([
                    'patient_id' => 'Paciente não encontrado nesta entidade.',
                ]);
            }

            $diagnoses = [];

            if (! empty($data['diagnoses'])) {
                $diagnoses = $this->diagnosisCatalog->prepareDiagnoses($entityId, $data['diagnoses']);
            }

            $importBatchId = (string) Str::uuid();
            $directory     = "{$entityId}/{$patient->id}/exams";
            $uploadedPaths = [];

            try {
                $exams = collect($files)->map(function (UploadedFile $file) use (
                    $data,
                    $patient,
                    $importBatchId,
                    $directory,
                    $diagnoses,
                    &$uploadedPaths,
                ): PatientExam {
                    $path            = $this->storeArchive($file, $directory);
                    $uploadedPaths[] = $path;

                    $record = PatientExam::create([
                        'patient_id'                     => $patient->id,
                        'doctor_id'                      => $data['doctor_id'] ?? null,
                        'exam_id'                        => $data['exam_id'],
                        'entity_integrator_equipment_id' => $data['entity_integrator_equipment_id'] ?? null,
                        'archive'                        => $path,
                        'active'                         => true,
                        'source'                         => ExamSource::ExternalImport,
                        'external_origin'                => $data['external_origin'] ?? null,
                        'exam_performed_at'              => $data['exam_performed_at'],
                        'import_batch_id'                => $importBatchId,
                        'diagnosis_cids'                 => $diagnoses ?: null,
                    ]);

                    // afterCommit: em fila, só roda se a transação do lote
                    // confirmar — evita derivado de exame que sofreu rollback.
                    \App\Jobs\GenerateExamDerivatives::dispatch($record->id)->afterCommit();

                    return $record;
                });
            } catch (Throwable $e) {
                // Rollback manual dos uploads já feitos no S3 antes da transação
                // de banco reverter — evita órfão silencioso quando um arquivo
                // do meio do lote falha (ex.: putFileAs retorna false).
                foreach ($uploadedPaths as $path) {
                    Storage::disk('s3')->delete($path);
                }

                throw $e;
            }

            if (! empty($diagnoses)) {
                // Uma vez por diagnóstico único do lote, não uma vez por linha
                // criada — senão o contador de uso infla por N arquivos.
                $this->diagnosisCatalog->recordUsage($entityId, $diagnoses);
            }

            return $exams;
        });
    }

    /**
     * Mesmo padrão de PatientExamService::storeArchive(): upload private em
     * streaming, nome "{time()}_{uuid}.{ext}".
     *
     * @throws RuntimeException quando o upload falha
     */
    private function storeArchive(UploadedFile $file, string $directory): string
    {
        $fileName = sprintf('%d_%s.%s', time(), Str::uuid(), $file->getClientOriginalExtension());

        $path = Storage::disk('s3')->putFileAs($directory, $file, $fileName, 'private');

        if ($path === false) {
            throw new RuntimeException('Failed to upload external exam archive.');
        }

        return $path;
    }
}
