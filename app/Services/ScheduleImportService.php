<?php

namespace App\Services;

use App\Concerns\OpensImportFile;
use App\Enums\{ImportStatus, MedicalSpecialty, PatientMood, ScheduleAttendanceType, ScheduleSituation};
use App\Models\{Covenant, Doctor, Patient, Schedule, ScheduleImport, VisitType};
use Carbon\Carbon;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{DB, Log, Storage};
use RuntimeException;
use Throwable;

/**
 * Processa importações de agendamentos (Schedule — consultas com
 * paciente+médico; NÃO confundir com ScheduleEvent/schedule_events, que é
 * bloqueio/reunião avulsa e está fora de escopo) via CSV.
 *
 * Estrutura de leitura (delimitador, BOM, mapeamento de colunas) é um mirror
 * de PatientImportService/DoctorImportService.
 *
 * Diferenças de negócio importantes em relação aos outros dois imports:
 *   - NÃO usa ScheduleRequest nem ScheduleService::validateSlot() — dados
 *     históricos (inclusive já finalizados) não precisam respeitar a grade
 *     de horário atual do médico. Cria direto via Schedule::create(), o hook
 *     booted() do model gera o `code` (SDL-...) automaticamente.
 *   - NÃO há dedupe/skip de linha — cada linha válida sempre CRIA um novo
 *     Schedule (skipped_rows fica sempre 0, mantido só por paridade de
 *     schema com patient_imports/doctor_imports).
 *   - NÃO checa FeatureGateService — não existe feature key de limite de
 *     agendamentos (ver App\Enums\FeatureKey) e dados históricos de migração
 *     não devem ser bloqueados por limite de plano atual.
 *   - Médico é OBRIGATÓRIO (resolvido por import_code, fallback CRM/record).
 *     Paciente é OPCIONAL (patient_id pode ficar null — full_name cobre o
 *     agendamento avulso, já suportado pelo sistema).
 *   - Conflito de horário (índice parcial `doctor_id`+`date_time` que exclui
 *     situações terminais — ver migration
 *     2026_03_24_000010_fix_schedules_unique_index_exclude_terminals) vira
 *     UniqueConstraintViolationException, capturada por linha e convertida
 *     na MESMA mensagem usada por SchedulesController::store()/update()
 *     (chave de tradução validation.custom.schedule.doctor_datetime_unique).
 *
 * Decisões de design não detalhadas na spec original (documentadas aqui):
 *   - Dicionários de rótulo→enum para situação/tipo de atendimento/
 *     especialidade/humor são fixos em português normalizado (sem acento,
 *     minúsculo, espaços viram "_") — ver as constantes *_LABELS abaixo.
 *   - `situacao` ausente/vazia na linha assume ScheduleSituation::Scheduled
 *     (Agendado) por padrão; se vier preenchida mas não bater com o
 *     dicionário nem for um inteiro válido do enum, a linha vira erro (regra
 *     explícita da spec).
 *   - Para os demais enums opcionais (tipo de atendimento, especialidade,
 *     humor), um valor não reconhecido é silenciosamente ignorado (fica
 *     null) em vez de rejeitar a linha inteira — são enriquecimentos
 *     opcionais, não dados obrigatórios do agendamento.
 *   - Resolução de paciente por CPF usa uma coluna adicional (`cpf_paciente`)
 *     não listada explicitamente nas "colunas sugeridas" da spec, mas
 *     necessária para cumprir a regra de fallback por CPF descrita no
 *     mesmo documento.
 */
class ScheduleImportService
{
    use OpensImportFile;

    /** Mapa cabeçalho normalizado → campo. Prefixo _ = campo resolvido/derivado, não coluna direta de `schedules`. */
    private const COLUMN_MAP = [
        // Médico (resolução obrigatória)
        'codigo_importacao_medico' => '_doctor_import_code',
        'codigo_medico'            => '_doctor_import_code',
        'crm_medico'               => '_doctor_record',
        'crm'                      => '_doctor_record',
        'record_medico'            => '_doctor_record',
        // Paciente (resolução opcional)
        'codigo_importacao_paciente' => '_patient_import_code',
        'codigo_paciente'            => '_patient_import_code',
        'cpf_paciente'               => '_patient_cpf',
        'cpf'                        => '_patient_cpf',
        'documento_paciente'         => '_patient_cpf',
        // Dados da consulta
        'nome_paciente'       => 'full_name',
        'nome'                => 'full_name',
        'paciente'            => 'full_name',
        'full_name'           => 'full_name',
        'data_hora'           => 'date_time',
        'data'                => 'date_time',
        'data_consulta'       => 'date_time',
        'date_time'           => 'date_time',
        'situacao'            => '_situation',
        'situation'           => '_situation',
        'tipo_atendimento'    => '_attendance_type',
        'atendimento'         => '_attendance_type',
        'especialidade'       => '_specialty_area',
        'convenio'            => '_covenant',
        'convenio_nome'       => '_covenant',
        'plano'               => '_covenant',
        'visita'              => '_visit',
        'tipo_visita'         => '_visit',
        'visit'               => '_visit',
        'telefone'            => 'telephone',
        'telefone_fixo'       => 'telephone',
        'telephone'           => 'telephone',
        'celular'             => 'cellphone',
        'telefone_celular'    => 'cellphone',
        'cellphone'           => 'cellphone',
        'observacoes'         => 'notes',
        'observacao'          => 'notes',
        'notes'               => 'notes',
        'motivo_cancelamento' => 'cancellation_reason',
        'cancellation_reason' => 'cancellation_reason',
        'humor_paciente'      => '_patient_mood',
        'humor'               => '_patient_mood',
        'patient_mood'        => '_patient_mood',
        'chegada'             => 'arrived_at',
        'arrived_at'          => 'arrived_at',
        'confirmado_em'       => 'confirmed_at',
        'confirmed_at'        => 'confirmed_at',
        // schedules.import_code (código do sistema anterior desta própria linha)
        'codigo_importacao'       => '_import_code',
        'codigo_sistema_anterior' => '_import_code',
        'codigo_legado'           => '_import_code',
        'import_code'             => '_import_code',
    ];

    /** Rótulos legíveis para cada campo do sistema (usados no preview). */
    private const FIELD_LABELS = [
        '_doctor_import_code'  => 'Código de importação do médico',
        '_doctor_record'       => 'CRM do médico',
        '_patient_import_code' => 'Código de importação do paciente',
        '_patient_cpf'         => 'CPF do paciente',
        'full_name'            => 'Nome do paciente',
        'date_time'            => 'Data/hora do agendamento',
        '_situation'           => 'Situação',
        '_attendance_type'     => 'Tipo de atendimento',
        '_specialty_area'      => 'Especialidade',
        '_covenant'            => 'Convênio',
        '_visit'               => 'Tipo de visita',
        'telephone'            => 'Telefone',
        'cellphone'            => 'Celular',
        'notes'                => 'Observações',
        'cancellation_reason'  => 'Motivo de cancelamento',
        '_patient_mood'        => 'Humor do paciente',
        'arrived_at'           => 'Chegada',
        'confirmed_at'         => 'Confirmado em',
        '_import_code'         => 'Código de importação (agendamento)',
    ];

    /** Colunas de valor único (não par de alternativas como médico) exigidas por linha. */
    private const REQUIRED_FIELDS = ['full_name', 'date_time'];

    private const DATE_FORMATS = [
        '!d/m/Y H:i:s',
        '!d/m/Y H:i',
        '!d-m-Y H:i:s',
        '!d-m-Y H:i',
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
        "!Y-m-d\TH:i:s",
        "!Y-m-d\TH:i",
        '!d/m/Y',
        '!d-m-Y',
        '!Y-m-d',
    ];

    /** Dicionário fixo rótulo (normalizado) → App\Enums\ScheduleSituation. */
    private const SITUATION_LABELS = [
        'agendado'              => 1,
        'agendada'              => 1,
        'confirmado'            => 2,
        'confirmada'            => 2,
        'aguardando'            => 3,
        'chegou'                => 3,
        'na_espera'             => 3,
        'dilatando'             => 4,
        'em_dilatacao'          => 4,
        'em_exame'              => 5,
        'exame'                 => 5,
        'em_espera'             => 10,
        'retornando'            => 10,
        'retornando_a_consulta' => 10,
        'aguardando_medico'     => 10,
        'em_consulta'           => 6,
        'em_atendimento'        => 6,
        'atendido'              => 7,
        'atendida'              => 7,
        'concluido'             => 7,
        'faltou'                => 8,
        'falta'                 => 8,
        'nao_compareceu'        => 8,
        'no_show'               => 8,
        'cancelado'             => 9,
        'cancelada'             => 9,
    ];

    /** Dicionário fixo rótulo (normalizado) → App\Enums\ScheduleAttendanceType. */
    private const ATTENDANCE_TYPE_LABELS = [
        'consulta'                 => 1,
        'retorno'                  => 2,
        'urgencia'                 => 3,
        'avaliacao_pre_operatoria' => 4,
        'pre_operatorio'           => 4,
        'preop'                    => 4,
        'avaliacao_pos_operatoria' => 5,
        'pos_operatorio'           => 5,
        'postop'                   => 5,
        'segunda_opiniao'          => 6,
        'teleconsulta'             => 7,
    ];

    /** Dicionário fixo rótulo (normalizado) → App\Enums\MedicalSpecialty. */
    private const SPECIALTY_LABELS = [
        'oftalmologia_geral' => 1,
        'geral'              => 1,
        'catarata'           => 2,
        'glaucoma'           => 3,
        'retina_e_vitreo'    => 4,
        'retina'             => 4,
        'cornea'             => 5,
        'oftalmopediatria'   => 6,
        'pediatrica'         => 6,
        'estrabismo'         => 7,
        'neuro_oftalmologia' => 8,
        'neuro'              => 8,
        'uveite'             => 9,
        'plastica_ocular'    => 10,
        'oculoplastica'      => 10,
        'refrativa'          => 11,
        'lentes_de_contato'  => 12,
        'baixa_visao'        => 13,
    ];

    /** Dicionário fixo rótulo (normalizado) → App\Enums\PatientMood. */
    private const MOOD_LABELS = [
        'tranquilo' => 1,
        'ansioso'   => 2,
        'agitado'   => 3,
        'irritado'  => 4,
    ];

    // ── Preview (leitura rápida das primeiras linhas) ─────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function generatePreview(ScheduleImport $import): array
    {
        $handle = $this->openImportFile($import->file_path);

        if (! $handle) {
            return ['error' => 'Arquivo não encontrado.'];
        }
        $this->skipBom($handle);
        $delimiter = $this->detectDelimiter($handle, 0);

        $rawHeaders = fgetcsv($handle, 0, $delimiter);

        if (! $rawHeaders) {
            fclose($handle);

            return ['error' => 'CSV sem cabeçalho ou vazio.'];
        }

        $indexFieldMap = $this->buildIndexFieldMap($rawHeaders);
        $hasCovenant   = $this->hasCovenantColumn($rawHeaders);

        $mappedColumns   = [];
        $unmappedColumns = [];

        foreach ($rawHeaders as $header) {
            $field = $this->fieldFor($this->normalizeKey($header), $hasCovenant);

            if ($field !== null) {
                $mappedColumns[] = [
                    'csv_header' => $header,
                    'field'      => $field,
                    'label'      => self::FIELD_LABELS[$field] ?? $field,
                    'required'   => in_array($field, self::REQUIRED_FIELDS, true),
                ];
            } else {
                $unmappedColumns[] = $header;
            }
        }

        $sampleRows = [];
        $totalRows  = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $totalRows++;

            if ($totalRows <= 5) {
                $mapped = [];

                foreach ($indexFieldMap as $i => $field) {
                    $label          = self::FIELD_LABELS[$field] ?? $field;
                    $mapped[$label] = isset($row[$i]) ? trim((string) $row[$i]) : '';
                }

                $sampleRows[] = $mapped;
            }
        }

        fclose($handle);

        $missingRequired = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! in_array($field, $indexFieldMap, true)) {
                $missingRequired[] = (self::FIELD_LABELS[$field] ?? $field) . ' (obrigatório)';
            }
        }

        $hasDoctorColumn = in_array('_doctor_import_code', $indexFieldMap, true)
                        || in_array('_doctor_record', $indexFieldMap, true);

        if (! $hasDoctorColumn) {
            $missingRequired[] = 'médico — codigo_importacao_medico ou crm_medico (obrigatório)';
        }

        return [
            'delimiter'        => $delimiter === "\t" ? 'tab' : $delimiter,
            'total_rows'       => $totalRows,
            'mapped_columns'   => $mappedColumns,
            'unmapped_columns' => $unmappedColumns,
            'sample_rows'      => $sampleRows,
            'missing_required' => $missingRequired,
            'can_proceed'      => empty($missingRequired),
        ];
    }

    // ── Ponto de entrada ──────────────────────────────────────────────────────

    public function process(ScheduleImport $import): void
    {
        // Cancelado enquanto ainda estava na fila (worker parado/reiniciado) —
        // não inicia. O controller já marcou o status ao receber o cancelamento.
        if ($import->fresh()->status === ImportStatus::Cancelled) {
            return;
        }

        $import->update(['status' => ImportStatus::Processing, 'started_at' => now()]);

        try {
            $this->doProcess($import);
        } catch (Throwable $e) {
            Log::error('ScheduleImport falhou', ['import_id' => $import->id, 'error' => $e->getMessage()]);

            $import->update([
                'status'       => ImportStatus::Failed,
                'abort_reason' => $e->getMessage(),
                'finished_at'  => now(),
            ]);
        }
    }

    // ── Processamento principal ───────────────────────────────────────────────

    private function doProcess(ScheduleImport $import): void
    {
        $handle = $this->openImportFile($import->file_path);

        if (! $handle) {
            throw new RuntimeException('Arquivo de importação não encontrado no disco.');
        }

        $bomOffset = $this->skipBom($handle);
        $delimiter = $this->detectDelimiter($handle, $bomOffset);

        $rawHeaders = fgetcsv($handle, 0, $delimiter);

        if (! $rawHeaders) {
            fclose($handle);

            throw new RuntimeException('CSV sem cabeçalho ou vazio.');
        }

        $indexFieldMap = $this->buildIndexFieldMap($rawHeaders);

        if (! in_array('full_name', $indexFieldMap, true)) {
            fclose($handle);

            throw new RuntimeException("Coluna obrigatória 'nome_paciente' não encontrada no CSV. Verifique o modelo.");
        }

        $totalRows = 0;

        while (fgetcsv($handle, 0, $delimiter) !== false) {
            $totalRows++;
        }

        $import->update(['total_rows' => $totalRows]);

        rewind($handle);
        $this->skipBom($handle);
        fgetcsv($handle, 0, $delimiter); // pula cabeçalho

        $entityId = (string) $import->entity_id;

        $covenantsMap    = $this->loadCovenantsMap($entityId);
        $visitTypesMap   = $this->loadVisitTypesMap($entityId);
        $usedImportCodes = $this->loadUsedImportCodes($entityId);

        $errors     = [];
        $imported   = 0;
        $skipped    = 0;
        $errorCount = 0;
        $processed  = 0;

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            // Cancelamento solicitado em outra request enquanto este job
            // rodava — para no próximo checkpoint, preservando o que já foi
            // processado (não reverte linhas já importadas).
            if ($import->fresh()->status === ImportStatus::Cancelled) {
                fclose($handle);
                $this->saveErrorsFile($import, $errors);
                $import->update([
                    'processed_rows' => $processed,
                    'imported_rows'  => $imported,
                    'skipped_rows'   => $skipped,
                    'error_rows'     => $errorCount,
                    'finished_at'    => now(),
                ]);

                return;
            }

            $processed++;
            $rowNum = $processed + 1; // +1 pela linha do cabeçalho

            $data = $this->mapRow($row, $indexFieldMap);

            if (trim((string) ($data['full_name'] ?? '')) === '') {
                $errors[] = $this->errorRow($rowNum, 'Nome do paciente (nome_paciente) obrigatório', $data);
                $errorCount++;
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);

                continue;
            }

            $dateTime = $this->parseDateTime((string) ($data['date_time'] ?? ''));

            if ($dateTime === null) {
                $errors[] = $this->errorRow($rowNum, 'Data/hora do agendamento (data_hora) obrigatória ou em formato inválido', $data);
                $errorCount++;
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);

                continue;
            }

            $data['date_time'] = $dateTime;

            try {
                DB::transaction(function () use ($data, $entityId, $covenantsMap, $visitTypesMap, &$usedImportCodes) {
                    $this->importRow($data, $entityId, $covenantsMap, $visitTypesMap, $usedImportCodes);
                });

                $imported++;
            } catch (Throwable $e) {
                $errors[] = $this->errorRow($rowNum, $e->getMessage(), $data);
                $errorCount++;
            }

            if ($processed % 25 === 0) {
                $this->updateProgress($import, $processed, $imported, $skipped, $errorCount);
            }
        }

        fclose($handle);
        $this->saveErrorsFile($import, $errors);

        $import->update([
            'status'         => ImportStatus::Done,
            'processed_rows' => $processed,
            'imported_rows'  => $imported,
            'skipped_rows'   => $skipped,
            'error_rows'     => $errorCount,
            'finished_at'    => now(),
        ]);
    }

    // ── Criação do agendamento ─────────────────────────────────────────────────

    /**
     * Cria o Schedule direto (sem ScheduleRequest/validateSlot — ver docblock
     * da classe), dentro da transação por linha aberta em doProcess().
     */
    private function importRow(
        array $data,
        string $entityId,
        array $covenantsMap,
        array $visitTypesMap,
        array &$usedImportCodes,
    ): void {
        $doctor = $this->resolveDoctor(
            $entityId,
            $data['_doctor_import_code'] ?? null,
            $data['_doctor_record'] ?? null,
        );

        if (! $doctor) {
            throw new RuntimeException('Médico não encontrado (verifique codigo_importacao_medico ou crm_medico).');
        }

        $patient = $this->resolvePatient(
            $entityId,
            $data['_patient_import_code'] ?? null,
            $data['_patient_cpf'] ?? null,
        );

        $situation = $this->resolveSituation((string) ($data['_situation'] ?? ''), (string) $data['date_time']);

        // tipo_atendimento é fallback do tipo de consulta: a agenda só exibe o
        // catálogo visit_types (as opções dos dois campos eram as mesmas).
        $attendanceType = $this->resolveAttendanceType($data['_attendance_type'] ?? null);
        $visitId        = $this->resolveVisitId($data['_visit'] ?? null, $visitTypesMap)
            ?? $this->resolveVisitId(ScheduleAttendanceType::tryFrom((int) $attendanceType)?->visitTypeName(), $visitTypesMap);

        $scheduleData = [
            'entity_id'           => $entityId,
            'doctor_id'           => $doctor->id,
            'patient_id'          => $patient?->id,
            'covenant_id'         => $this->resolveCovenantId($data['_covenant'] ?? null, $covenantsMap),
            'visit_id'            => $visitId,
            'attendance_type'     => $attendanceType,
            'specialty_area'      => $this->resolveSpecialty($data['_specialty_area'] ?? null),
            'full_name'           => mb_strtoupper(trim((string) $data['full_name']), 'UTF-8'),
            'date_time'           => $data['date_time'],
            'telephone'           => $this->onlyNumbers((string) ($data['telephone'] ?? '')) ?: null,
            'cellphone'           => $this->onlyNumbers((string) ($data['cellphone'] ?? '')) ?: null,
            'notes'               => $data['notes'] ?? null,
            'cancellation_reason' => $data['cancellation_reason'] ?? null,
            'situation'           => $situation,
            'arrived_at'          => ! empty($data['arrived_at']) ? $this->parseDateTime((string) $data['arrived_at']) : null,
            'confirmed_at'        => ! empty($data['confirmed_at']) ? $this->parseDateTime((string) $data['confirmed_at']) : null,
            'patient_mood'        => $this->resolveMood($data['_patient_mood'] ?? null),
            'active'              => true,
        ];

        try {
            $schedule = Schedule::create($scheduleData);
        } catch (UniqueConstraintViolationException $e) {
            if (Schedule::isDoctorSlotConflict($e)) {
                throw new RuntimeException(__('validation.custom.schedule.doctor_datetime_unique'));
            }

            // Outra violação (ex.: código SDL) NÃO é horário ocupado. A mensagem
            // crua do QueryException traz SQL + bindings (dados do paciente) e
            // iria para o arquivo de erros: registra só metadados e usa texto
            // traduzido na linha.
            Log::warning('schedule_import.unexpected_unique_violation', [
                'entity_id' => $entityId,
                'sql_state' => (string) $e->getCode(),
            ]);

            throw new RuntimeException(__('record_codes.unexpected_unique_conflict'));
        }

        $this->assignImportCode($schedule, $data['_import_code'] ?? null, $usedImportCodes);
    }

    /**
     * Grava o código de importação no agendamento recém-criado, garantindo
     * unicidade por entidade (contra o banco e contra outras linhas do mesmo
     * arquivo). Nunca é preenchido por outro caminho — ScheduleRequest/tela
     * normal não expõem nem validam este campo, e `import_code` não está no
     * $fillable de Schedule (forceFill é o único ponto de escrita).
     */
    private function assignImportCode(Schedule $schedule, mixed $importCode, array &$usedImportCodes): void
    {
        $importCode = trim((string) $importCode);

        if ($importCode === '') {
            return;
        }

        $key = mb_strtolower($importCode, 'UTF-8');

        if (isset($usedImportCodes[$key])) {
            throw new RuntimeException("Código de importação '{$importCode}' já utilizado por outro agendamento.");
        }

        try {
            $schedule->forceFill(['import_code' => $importCode])->save();
        } catch (UniqueConstraintViolationException) {
            // Rede de segurança contra corrida/edge-case não coberto pelo
            // mapa em memória — a constraint de banco é quem garante de fato.
            throw new RuntimeException("Código de importação '{$importCode}' já utilizado por outro agendamento.");
        }

        $usedImportCodes[$key] = true;
    }

    // ── Resolução de vínculos ────────────────────────────────────────────────

    /** Resolve o médico por import_code; fallback por CRM (doctors.record). Escopado à entidade via entity_users. */
    private function resolveDoctor(string $entityId, ?string $importCode, ?string $record): ?Doctor
    {
        $base = Doctor::query()
            ->join('entity_users', 'doctors.entity_user_id', '=', 'entity_users.id')
            ->where('entity_users.entity_id', $entityId)
            ->whereNull('doctors.deleted_at')
            ->select('doctors.*');

        $importCode = trim((string) $importCode);

        if ($importCode !== '') {
            $doctor = (clone $base)->where('doctors.import_code', $importCode)->first();

            if ($doctor) {
                return $doctor;
            }
        }

        $record = trim((string) $record);

        if ($record !== '') {
            return (clone $base)->where('doctors.record', $record)->first();
        }

        return null;
    }

    /** Resolve o paciente por import_code; fallback por CPF (people.national_registry via patients.person_id). Opcional — retorna null sem erro. */
    private function resolvePatient(string $entityId, ?string $importCode, ?string $cpf): ?Patient
    {
        $importCode = trim((string) $importCode);

        if ($importCode !== '') {
            $patient = Patient::where('entity_id', $entityId)
                ->where('import_code', $importCode)
                ->whereNull('deleted_at')
                ->first();

            if ($patient) {
                return $patient;
            }
        }

        $cpf = $this->onlyNumbers((string) $cpf);

        if ($cpf !== '') {
            return Patient::where('entity_id', $entityId)
                ->whereNull('deleted_at')
                ->whereHas('person', fn ($q) => $q->where('national_registry', $cpf))
                ->first();
        }

        return null;
    }

    private function resolveCovenantId(?string $name, array $covenantsMap): ?string
    {
        if (empty($name)) {
            return null;
        }

        return $covenantsMap[mb_strtolower(trim($name), 'UTF-8')] ?? null;
    }

    private function resolveVisitId(?string $name, array $visitTypesMap): ?string
    {
        if (empty($name)) {
            return null;
        }

        return $visitTypesMap[mb_strtolower(trim($name), 'UTF-8')] ?? null;
    }

    /** Situação vazia assume Agendado; valor fora do dicionário/enum vira erro de linha (regra explícita da spec). */
    private function resolveSituation(string $raw, string $dateTime): int
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ScheduleSituation::Scheduled->value;
        }

        // "AUSENTE" do sistema de origem era o estado inicial ("não chegou") e
        // nunca virava falta — agendamento passado ainda AUSENTE = faltou;
        // futuro = ainda agendado.
        if ($this->normalizeKey($raw) === 'ausente') {
            return Carbon::parse($dateTime)->isPast()
                ? ScheduleSituation::NoShow->value
                : ScheduleSituation::Scheduled->value;
        }

        if (is_numeric($raw)) {
            $int = (int) $raw;

            if (ScheduleSituation::tryFrom($int) !== null) {
                return $int;
            }

            throw new RuntimeException("Situação '{$raw}' inválida.");
        }

        $key = $this->normalizeKey($raw);

        if (isset(self::SITUATION_LABELS[$key])) {
            return self::SITUATION_LABELS[$key];
        }

        throw new RuntimeException("Situação '{$raw}' não reconhecida. Verifique o dicionário de situações do modelo.");
    }

    /** Enriquecimento opcional — valor não reconhecido é ignorado (null), não rejeita a linha. */
    private function resolveAttendanceType(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return ScheduleAttendanceType::tryFrom((int) $raw)?->value;
        }

        return self::ATTENDANCE_TYPE_LABELS[$this->normalizeKey($raw)] ?? null;
    }

    /** Enriquecimento opcional — valor não reconhecido é ignorado (null), não rejeita a linha. */
    private function resolveSpecialty(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return MedicalSpecialty::tryFrom((int) $raw)?->value;
        }

        return self::SPECIALTY_LABELS[$this->normalizeKey($raw)] ?? null;
    }

    /** Enriquecimento opcional — valor não reconhecido é ignorado (null), não rejeita a linha. */
    private function resolveMood(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return PatientMood::tryFrom((int) $raw)?->value;
        }

        return self::MOOD_LABELS[$this->normalizeKey($raw)] ?? null;
    }

    // ── Convênios / Tipos de visita ──────────────────────────────────────────

    /** Mapa nome-em-minúsculo → UUID, escopado à entidade (ou global). */
    private function loadCovenantsMap(string $entityId): array
    {
        return Covenant::where(function ($query) use ($entityId) {
            $query->where('entity_id', $entityId)
                ->orWhere('entity_id', null);
        })
            ->whereNull('deleted_at')
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name, 'UTF-8') => (string) $id])
            ->toArray();
    }

    /** Mapa nome-em-minúsculo → UUID, escopado à entidade (ou global). */
    private function loadVisitTypesMap(string $entityId): array
    {
        return VisitType::where(function ($query) use ($entityId) {
            $query->where('entity_id', $entityId)
                ->orWhere('entity_id', null);
        })
            ->whereNull('deleted_at')
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtolower($name, 'UTF-8') => (string) $id])
            ->toArray();
    }

    /** Carrega os import_code já em uso nesta entidade (minúsculo → true). */
    private function loadUsedImportCodes(string $entityId): array
    {
        return Schedule::where('entity_id', $entityId)
            ->whereNotNull('import_code')
            ->pluck('import_code')
            ->mapWithKeys(fn ($code) => [mb_strtolower((string) $code, 'UTF-8') => true])
            ->toArray();
    }

    // ── Mapeamento de colunas ─────────────────────────────────────────────────

    private function buildIndexFieldMap(array $headers): array
    {
        $map         = [];
        $hasCovenant = $this->hasCovenantColumn($headers);

        foreach ($headers as $i => $header) {
            $field = $this->fieldFor($this->normalizeKey($header), $hasCovenant);

            if ($field !== null) {
                $map[$i] = $field;
            }
        }

        return $map;
    }

    /**
     * "plano" só vale como convênio quando a planilha não tem coluna de
     * convênio. Com as duas, "plano" é o plano do paciente (o agendamento não
     * tem plano) e não pode sobrescrever o convênio da linha.
     */
    private function fieldFor(string $normalized, bool $hasCovenantColumn): ?string
    {
        if ($hasCovenantColumn && $normalized === 'plano') {
            return null;
        }

        return self::COLUMN_MAP[$normalized] ?? null;
    }

    private function hasCovenantColumn(array $headers): bool
    {
        $keys = array_map(fn ($header) => $this->normalizeKey((string) $header), $headers);

        return array_intersect($keys, ['convenio', 'convenio_nome']) !== [];
    }

    private function mapRow(array $row, array $indexFieldMap): array
    {
        $data = [];

        foreach ($indexFieldMap as $i => $field) {
            $raw          = isset($row[$i]) ? trim((string) $row[$i]) : '';
            $data[$field] = $raw !== '' ? $raw : null;
        }

        return $data;
    }

    // ── Helpers de parsing ────────────────────────────────────────────────────

    private function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key), 'UTF-8');
        $key = str_replace([' ', '-'], '_', $key);
        // Remove acentos via transliteração ASCII
        $key = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key) ?: $key;

        return preg_replace('/[^a-z0-9_]/', '', $key) ?? $key;
    }

    private function detectDelimiter(mixed $handle, int $bomOffset): string
    {
        $pos    = ftell($handle);
        $sample = fgets($handle);
        fseek($handle, $pos);

        if ($sample === false) {
            return ';';
        }

        $candidates = [';' => 0, ',' => 0, "\t" => 0];

        foreach (array_keys($candidates) as $delim) {
            $candidates[$delim] = substr_count($sample, $delim);
        }
        arsort($candidates);

        return array_key_first($candidates) ?? ';';
    }

    /** Avança além do BOM UTF-8 se presente. Retorna offset consumido (3 ou 0). */
    private function skipBom(mixed $handle): int
    {
        $bom = fread($handle, 3);

        if ($bom !== "\xEF\xBB\xBF") {
            fseek($handle, 0);

            return 0;
        }

        return 3;
    }

    /**
     * Aceita data pura ou data+hora, nos formatos declarados em
     * DATE_FORMATS. O prefixo "!" reseta campos não informados no formato
     * para o epoch (00:00:00), evitando que Carbon/DateTime preencha a hora
     * ausente com o horário ATUAL (comportamento padrão de createFromFormat
     * sem o "!", que corromperia silenciosamente a hora do agendamento).
     */
    private function parseDateTime(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);

                if ($date && $date->year > 1900 && $date->year <= now()->year + 1) {
                    return $date->format('Y-m-d H:i:s');
                }
            } catch (Exception) {
                // tenta próximo formato
            }
        }

        return null;
    }

    private function onlyNumbers(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    // ── Progresso e erros ─────────────────────────────────────────────────────

    private function updateProgress(ScheduleImport $import, int $processed, int $imported, int $skipped, int $errors): void
    {
        $import->update([
            'processed_rows' => $processed,
            'imported_rows'  => $imported,
            'skipped_rows'   => $skipped,
            'error_rows'     => $errors,
        ]);
    }

    private function errorRow(int $lineNum, string $reason, array $data): array
    {
        return array_merge(['_linha' => $lineNum, '_erro' => $reason], $data);
    }

    /** Salva CSV de erros no disco privado e registra o caminho no import. */
    private function saveErrorsFile(ScheduleImport $import, array $errors): void
    {
        if (empty($errors)) {
            return;
        }

        $path   = "imports/schedules/{$import->entity_id}/errors_{$import->id}.csv";
        $stream = fopen('php://temp', 'r+');

        fwrite($stream, "\xEF\xBB\xBF");

        fputcsv($stream, array_keys(reset($errors)), ';');

        foreach ($errors as $error) {
            fputcsv($stream, array_map('strval', array_values($error)), ';');
        }

        rewind($stream);
        Storage::disk()->put($path, stream_get_contents($stream));
        fclose($stream);

        $import->update(['errors_file_path' => $path]);
    }
}
