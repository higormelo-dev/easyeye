<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\{ExamSource, ScheduleSituation};
use App\Jobs\GenerateExamDerivatives;
use App\Models\{Patient, PatientExam, Schedule};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\{Collection, Str};
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Importação manual de exame externo (upload avulso, sem integrador) no
 * Gerenciador de exames. Cria uma linha de PatientExam por arquivo enviado
 * — archive é string NOT NULL, então "um exame com N arquivos" vira N
 * registros compartilhando o mesmo import_batch_id (agrupamento visual no
 * front, sem tabela própria de "lote").
 *
 * Mesmo padrão de storage sensível (LGPD art. 11) de
 * PatientExamService::storeArchive(): disco s3, visibilidade private,
 * diretório "{entity}/{patient}/exams", nome "{time()}_{uuid}.{ext}". Lido
 * depois pelas MESMAS rotas de ExamManagerController (patientExamUrls/imageUrl)
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

            // Origem de CADA arquivo (files_source, índice-alinhado com `files`):
            // um lote misto — scan externo + foto da câmera — não pode herdar a
            // origem de um só arquivo. Captura pela câmera conta como produção
            // da clínica no repasse médico (DoctorPayoutProductionService);
            // importação externa fica de fora de propósito. Marcar o scan
            // externo como captura o pagava como produção, e desativar a
            // captura de verdade depois não tirava o scan do repasse — achado
            // via revisão adversarial Codex.
            $sources = collect($files)
                ->keys()
                ->mapWithKeys(fn (int $index): array => [$index => $this->sourceOf($data, $index)])
                ->all();

            // Captura pela câmera (Fase 5 / 6b): sem schedule_id, a mesma
            // consulta já atendida virava 2 atos: o agendamento (fonte 1) E o
            // exame "sem vínculo" (fonte 3), inflando a previsão de repasse —
            // achado via revisão adversarial Codex. Resolvido uma vez por lote
            // (exam_performed_at/doctor_id são do lote inteiro, não por
            // arquivo) e SÓ aplicado às capturas — o scan externo do mesmo lote
            // nunca é vinculado a agendamento; nunca adivinha se houver mais de
            // um agendamento atendido pro MESMO paciente no MESMO dia
            // (ambíguo demais pra vincular sozinho) — fica sem vínculo.
            $scheduleId = in_array(ExamSource::BrowserCapture, $sources, true)
                ? $this->resolveAttendedScheduleId($entityId, $patient->id, $data['doctor_id'] ?? null, $data['exam_performed_at'])
                : null;

            try {
                $exams = collect($files)->map(function (UploadedFile $file, int $index) use (
                    $data,
                    $patient,
                    $importBatchId,
                    $directory,
                    $diagnoses,
                    $sources,
                    $scheduleId,
                    &$uploadedPaths,
                ): PatientExam {
                    $source          = $sources[$index];
                    $path            = $this->storeArchive($file, $directory);
                    $uploadedPaths[] = $path;

                    $record = PatientExam::create([
                        'patient_id'                     => $patient->id,
                        'doctor_id'                      => $data['doctor_id'] ?? null,
                        'schedule_id'                    => $source === ExamSource::BrowserCapture ? $scheduleId : null,
                        'exam_id'                        => $data['exam_id'],
                        'entity_integrator_equipment_id' => $data['entity_integrator_equipment_id'] ?? null,
                        // Olho por arquivo (Fase 5 / 7b — pedal "avançar" entre
                        // capturas da mesma sessão) cai no olho único só quando
                        // este índice não mandou nada — ImportExternalExamRequest.
                        // array_key_exists (não ??): AO explícito grava null NESTE
                        // índice (a chave existe); ?? não distingue "existe e é
                        // null" de "não existe" e cairia pro laterality top-level.
                        'laterality' => array_key_exists($index, $data['files_laterality'] ?? [])
                            ? $data['files_laterality'][$index]
                            : ($data['laterality'] ?? null),
                        'archive' => $path,
                        'active'  => true,
                        // Fase 5 / 6b — 'browser_capture' quando ESTA foto/vídeo veio da
                        // câmera do navegador; senão o upload manual de sempre.
                        'source'            => $source,
                        'external_origin'   => $data['external_origin'] ?? null,
                        'exam_performed_at' => $data['exam_performed_at'],
                        'import_batch_id'   => $importBatchId,
                        'diagnosis_cids'    => $diagnoses ?: null,
                    ]);

                    // afterCommit: em fila, só roda se a transação do lote
                    // confirmar — evita derivado de exame que sofreu rollback.
                    DB::afterCommit(static function () use ($record): void {
                        try {
                            GenerateExamDerivatives::dispatch($record->id, $record->archive)->beforeCommit();
                        } catch (Throwable $error) {
                            // The primary is committed. Failed derivatives remain
                            // explicit/recoverable and cannot turn its upload into500.
                            report($error);
                        }
                    });

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
     * Origem do arquivo $index do lote. Só 'external_import' e 'browser_capture'
     * existem aqui (o request já recusa o resto); ausente, inválida ou
     * 'integrator' — reservado ao pipeline do equipamento — cai em
     * 'external_import', a origem que NÃO entra no repasse.
     *
     * @param array<string, mixed> $data
     */
    private function sourceOf(array $data, int $index): ExamSource
    {
        $source = ExamSource::tryFrom((string) ($data['files_source'][$index] ?? ''));

        return $source === ExamSource::BrowserCapture ? $source : ExamSource::ExternalImport;
    }

    /**
     * Agendamento atendido do paciente no dia do exame — vínculo usado por
     * DoctorPayoutProductionService pra nunca contar a mesma consulta como
     * 2 atos (o agendamento E o "exame de equipamento sem vínculo"). Exige
     * isolamento por clínica (entity_id) e, se um médico foi informado na
     * captura, o MESMO médico do agendamento — nunca vincula ao agendamento
     * de outro médico só porque é do mesmo paciente/dia. Mais de um
     * agendamento atendido bate no mesmo filtro (paciente com 2 consultas
     * atendidas no mesmo dia): ambíguo demais pra escolher sozinho, fica
     * sem vínculo — mesmo comportamento de hoje, nunca um palpite errado.
     */
    private function resolveAttendedScheduleId(string $entityId, string $patientId, ?string $doctorId, string $examPerformedAt): ?string
    {
        // Lock paciente/dia ANTES de qualquer linha de agendamento: a
        // transição pra Atendido (changeSituation → reconciliação, abaixo)
        // trava a própria linha e depois TODOS os agendamentos do dia, como
        // esta consulta — sem uma ordem comum, as duas esperavam uma a outra
        // (deadlock). Ver Schedule::lockPatientDay.
        Schedule::lockPatientDay($entityId, $patientId, $examPerformedAt);

        $lockCandidates = Schedule::query()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->whereDate('date_time', $examPerformedAt);

        if ($doctorId !== null) {
            $lockCandidates->where('doctor_id', $doctorId);
        }

        // Trava TODOS os agendamentos candidatos do dia — não só os já
        // Attended — senão um agendamento ainda Waiting/Scheduled no
        // instante desta leitura, mas virando Attended CONCORRENTEMENTE em
        // ScheduleService::changeSituation() (que trava a MESMA linha via
        // refreshSituationUnderLock()), nunca serializa contra esta
        // consulta: a leitura via "ainda não atendido" e o INSERT do
        // PatientExam com schedule_id nulo podiam terminar bem no meio da
        // transição, e relinkBrowserCapturesForSchedule() já ter rodado
        // ANTES desse insert comitar — órfão pra sempre (changeSituation
        // nunca roda a reconciliação 2x pro mesmo agendamento). Mesma
        // lição do backfill de IA legado desta sessão: FOR UPDATE só no
        // filtro final (situation = Attended) não protege uma linha que
        // ainda vai passar a bater nele. Achado via revisão adversarial
        // Codex.
        $lockCandidates->lockForUpdate()->get();

        $query = Schedule::query()
            ->where('entity_id', $entityId)
            ->where('patient_id', $patientId)
            ->where('situation', ScheduleSituation::Attended->value)
            ->whereDate('date_time', $examPerformedAt);

        if ($doctorId !== null) {
            $query->where('doctor_id', $doctorId);
        }

        $ids = $query->limit(2)->pluck('id');

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }

    /**
     * Direção contrária de resolveAttendedScheduleId(): quando a captura
     * aconteceu ANTES do agendamento virar Attended (ex.: foto tirada
     * enquanto o paciente ainda estava Aguardando), ela ficava pra sempre
     * sem schedule_id — DoctorPayoutProductionService contava o agendamento
     * (fonte 1) E essa captura "sem vínculo" (fonte 3) como 2 atos (achado
     * via revisão adversarial Codex). Chamado por
     * ScheduleService::changeSituation() sempre que um agendamento passa a
     * Attended: procura capturas órfãs do mesmo paciente/dia ainda sem
     * vínculo e tenta resolver de novo, reaproveitando a MESMA regra de
     * ambiguidade (médico diferente ou 2+ agendamentos atendidos no mesmo
     * dia nunca vinculam sozinhos).
     */
    public function relinkBrowserCapturesForSchedule(Schedule $schedule): void
    {
        PatientExam::query()
            ->where('source', ExamSource::BrowserCapture->value)
            ->whereNull('schedule_id')
            ->where('patient_id', $schedule->patient_id)
            ->whereDate('exam_performed_at', $schedule->date_time)
            ->where(fn ($q) => $q->whereNull('doctor_id')->orWhere('doctor_id', $schedule->doctor_id))
            ->get()
            ->each(function (PatientExam $exam) use ($schedule): void {
                $resolvedId = $this->resolveAttendedScheduleId(
                    (string) $schedule->entity_id,
                    (string) $schedule->patient_id,
                    $exam->doctor_id,
                    $exam->exam_performed_at->toDateString(),
                );

                if ($resolvedId === (string) $schedule->id) {
                    $exam->update(['schedule_id' => $schedule->id]);
                }
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
        // guessExtension() (baseado no MIME de verdade — getMimeType(), o
        // mesmo que a regra "mimes:" de ImportExternalExamRequest usa pra
        // validar), nunca getClientOriginalExtension() (nome do arquivo,
        // 100% controlado pelo cliente): uma imagem renomeada pra .mp4
        // salvava com ESSA extensão no S3 mesmo passando a validação como
        // imagem — PatientExam::isVideo() (extensão do archive) classificava
        // errado e a tela tentava tocar um <video> quebrado em vez de
        // mostrar a imagem que sempre funcionou; o inverso também valia pra
        // um vídeo de verdade renomeado pra .jpg (achado via revisão Codex).
        $fileName = sprintf('%d_%s.%s', time(), Str::uuid(), $file->guessExtension());

        $path = Storage::disk('s3')->putFileAs($directory, $file, $fileName, 'private');

        if ($path === false) {
            throw new RuntimeException('Failed to upload external exam archive.');
        }

        return $path;
    }
}
