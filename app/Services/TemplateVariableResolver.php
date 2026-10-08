<?php

namespace App\Services;

use App\Models\{Doctor, Entity, MedicalRecord, Patient, ReportSettingContent, ReportSettingVariable};
use App\Support\BrazilianFormat;
use Carbon\{Carbon, CarbonInterface};
use DateTimeInterface;

/**
 * Resolve variáveis dinâmicas em templates de documentação clínica.
 *
 * Fontes suportadas:
 *   - patient      → Person do paciente (full_name, CPF em national_registry, birth_date, age)
 *   - doctor       → Person do médico (full_name, record, record_specialty)
 *   - entity       → Dados da clínica (name, address, phone)
 *   - medical_record → Campos clínicos do prontuário (tonometria, refração, etc.)
 *   - date         → Data atual em diferentes formatos
 *   - custom       → Valor default ou campo em branco para preenchimento manual
 */
class TemplateVariableResolver
{
    /** Data dos documentos desta resolução (data alternativa — Fase 2 / B3). */
    private ?CarbonInterface $documentDate = null;

    /**
     * Resolve o conteúdo de um template substituindo todas as variáveis.
     *
     * @param CarbonInterface|null $documentDate data impressa no documento
     *                                           (data alternativa — Fase 2 / B3); null = hoje. Vale para
     *                                           toda variável de data ({{DATA_ATUAL}}, {{DATA_EXTENSO}},
     *                                           {{LOCAL_DATA}} e as do tipo `date`), nunca para {{DATA_EXAME}}.
     *
     * @return array{html: string, unresolved: array<string, string>}
     */
    public function resolve(
        ReportSettingContent $content,
        Patient $patient,
        Doctor $doctor,
        Entity $entity,
        ?MedicalRecord $medicalRecord = null,
        ?CarbonInterface $documentDate = null,
    ): array {
        $patient->loadMissing('person');
        $doctor->loadMissing('person');
        $this->documentDate = $documentDate;

        // 1. Coletar variáveis definidas na tabela
        $variables = $content->activeVariables()->orderBy('sort_order')->get();

        // 2. Construir mapa de substituição base (fallback do sistema)
        $systemReplacements = $this->buildSystemReplacements($patient, $doctor, $entity, $medicalRecord);

        // 3. Resolver variáveis da tabela
        $tableReplacements = [];
        $unresolved        = [];

        foreach ($variables as $variable) {
            $value = $this->resolveVariable($variable, $patient, $doctor, $entity, $medicalRecord);

            if ($value !== null) {
                $tableReplacements[$variable->placeholder] = $value;
            } else {
                $unresolved[$variable->placeholder] = $variable->label ?? $variable->placeholder;
            }
        }

        // 4. Merge: variáveis da tabela têm prioridade sobre fallback do sistema
        $allReplacements = array_merge($systemReplacements, $tableReplacements);

        // 5. Substituir no conteúdo
        $html = str_replace(
            array_keys($allReplacements),
            array_values($allReplacements),
            $content->content,
        );

        return [
            'html'       => $html,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Valores que o resolver colocaria no texto deste modelo para ESTE
     * paciente/médico/clínica/consulta — só as fontes que identificam o
     * atendimento (paciente, médico, clínica, data, prontuário); `custom` e
     * defaults estáticos ficam fora. Usado para devolver o texto de um modelo
     * pessoal às variáveis antes de salvar (Fase 2 / B3 — modelo reutilizável
     * não guarda dado de paciente).
     *
     * @return array<string, string> placeholder => valor resolvido
     */
    public function identityReplacements(
        ReportSettingContent $content,
        Patient $patient,
        Doctor $doctor,
        Entity $entity,
        ?MedicalRecord $medicalRecord = null,
        ?CarbonInterface $documentDate = null,
    ): array {
        $patient->loadMissing('person');
        $doctor->loadMissing('person');
        $this->documentDate = $documentDate;

        $map = $this->buildSystemReplacements($patient, $doctor, $entity, $medicalRecord);

        foreach ($content->activeVariables()->orderBy('sort_order')->get() as $variable) {
            if (! in_array($variable->source_type, ['patient', 'doctor', 'entity', 'date', 'medical_record'], true)) {
                continue;
            }

            $value = $this->resolveVariable($variable, $patient, $doctor, $entity, $medicalRecord);

            if ($value !== null && $value !== '') {
                // Mesma prioridade do resolve(): a variável da tabela vence o padrão.
                $map[$variable->placeholder] = $value;
            }
        }

        return array_filter($map, fn ($value) => is_string($value) && trim($value) !== '');
    }

    /**
     * Retorna todas as variáveis disponíveis agrupadas, para uso no editor de templates.
     *
     * @return array<string, array<int, array{placeholder: string, label: string, description: string|null}>>
     */
    public function getAvailableVariables(): array
    {
        return [
            'paciente' => [
                ['placeholder' => '{{PACIENTE_NOME}}', 'label' => 'Nome do Paciente', 'description' => 'Nome completo do paciente'],
                ['placeholder' => '{{PACIENTE_CPF}}', 'label' => 'CPF do Paciente', 'description' => 'CPF formatado'],
                ['placeholder' => '{{PACIENTE_DATA_NASCIMENTO}}', 'label' => 'Data de Nascimento', 'description' => 'Formato dd/mm/aaaa'],
                ['placeholder' => '{{PACIENTE_IDADE}}', 'label' => 'Idade do Paciente', 'description' => 'Idade em anos'],
            ],
            'medico' => [
                ['placeholder' => '{{MEDICO_NOME}}', 'label' => 'Nome do Médico', 'description' => 'Nome completo do médico'],
                ['placeholder' => '{{MEDICO_CRM}}', 'label' => 'CRM do Médico', 'description' => 'Número do registro CRM'],
                ['placeholder' => '{{MEDICO_CRM_ESPECIALIDADE}}', 'label' => 'Especialidade CRM', 'description' => 'Especialidade registrada no CRM'],
            ],
            'clinica' => [
                ['placeholder' => '{{CLINICA_NOME}}', 'label' => 'Nome da Clínica', 'description' => 'Nome da entidade/clínica'],
                ['placeholder' => '{{CLINICA_ENDERECO}}', 'label' => 'Endereço da Clínica', 'description' => 'Endereço completo'],
                ['placeholder' => '{{CLINICA_TELEFONE}}', 'label' => 'Telefone da Clínica', 'description' => 'Telefone principal'],
            ],
            'data' => [
                ['placeholder' => '{{DATA_ATUAL}}', 'label' => 'Data Atual', 'description' => 'Formato dd/mm/aaaa'],
                ['placeholder' => '{{DATA_EXTENSO}}', 'label' => 'Data por Extenso', 'description' => 'Ex: segunda-feira, 3 de abril de 2026'],
                ['placeholder' => '{{DATA_EXAME}}', 'label' => 'Data do Exame', 'description' => 'Data de criação do prontuário'],
                ['placeholder' => '{{LOCAL_DATA}}', 'label' => 'Local e Data', 'description' => 'Cidade/UF e data por extenso (ex: São Paulo/SP, 13 de maio de 2026)'],
            ],
            'auxiliares' => [
                ['placeholder' => '{{CABECALHO_PACIENTE}}', 'label' => 'Cabeçalho do Paciente', 'description' => 'Bloco com nome, idade e data do exame (HTML)'],
            ],
            // Fase 2 / B1-B2: preenchidas na emissão (CertificateBuilder e
            // ProcedureSolicitationService::buildRequest), não pelo resolver.
            'atestados' => [
                ['placeholder' => '{{DATA_COMPARECIMENTO}}', 'label' => 'Dia do comparecimento', 'description' => 'Formato dd/mm/aaaa (em branco = data do documento)'],
                ['placeholder' => '{{HORARIO_COMPARECIMENTO}}', 'label' => 'Horário ou período', 'description' => 'Ex.: ", das 08:10 às 09:30" ou ", no período da manhã"'],
                ['placeholder' => '{{ORIENTACAO_DILATACAO}}', 'label' => 'Orientação de dilatação pupilar', 'description' => 'Parágrafo: não dirigir/operar máquinas por N horas'],
                ['placeholder' => '{{ACOMPANHANTE_NOME}}', 'label' => 'Nome do acompanhante', 'description' => 'Atestado de acompanhante'],
                ['placeholder' => '{{ACOMPANHANTE_DOCUMENTO}}', 'label' => 'Documento do acompanhante', 'description' => 'Ex.: ", portador(a) do documento RG 123," (opcional)'],
                ['placeholder' => '{{CID_ATESTADO}}', 'label' => 'CID autorizado pelo paciente', 'description' => 'Parágrafo com o CID-10 — só sai com a autorização (Res. CFM 1.658/2002, art. 5º)'],
                ['placeholder' => '{{TABELA_ACUIDADE_VISUAL}}', 'label' => 'Tabela de acuidade visual', 'description' => 'AV sem/com correção do prontuário'],
                ['placeholder' => '{{CONCLUSAO_APTIDAO}}', 'label' => 'Conclusão da aptidão visual', 'description' => 'Apto / apto com correção / inapto'],
                ['placeholder' => '{{FINALIDADE_APTIDAO}}', 'label' => 'Finalidade da aptidão visual', 'description' => 'Trabalho, esporte, concurso ou outra'],
            ],
            'pedido_exames' => [
                ['placeholder' => '{{PROCEDIMENTOS_SOLICITADOS}}', 'label' => 'Exames do pedido', 'description' => 'Lista com código, caráter, indicação, CID-10 e observações'],
            ],
        ];
    }

    // ─── Private ────────────────────────────────────────────────────────────

    private function resolveVariable(
        ReportSettingVariable $variable,
        Patient $patient,
        Doctor $doctor,
        Entity $entity,
        ?MedicalRecord $medicalRecord,
    ): ?string {
        return match ($variable->source_type) {
            'patient'        => $this->resolvePatient($variable, $patient),
            'doctor'         => $this->resolveDoctor($variable, $doctor),
            'entity'         => $this->resolveEntity($variable, $entity),
            'date'           => $this->resolveDate($variable),
            'medical_record' => $this->resolveMedicalRecord($variable, $medicalRecord),
            // Custom sem default = placeholder de preenchimento externo
            // (payload da quick-action). Retorna null p/ não substituir aqui;
            // o caller (MedicalRecordQuickActionService) injeta o valor real.
            'custom' => ($variable->default_value === null || $variable->default_value === '')
                ? null
                : $variable->default_value,
            default => $variable->default_value,
        };
    }

    private function resolvePatient(ReportSettingVariable $variable, Patient $patient): ?string
    {
        return match ($variable->source_field) {
            'person.full_name'  => $patient->person?->full_name ?? '',
            'person.cpf'        => $this->patientCpf($patient),
            'person.birth_date' => $patient->person?->birth_date?->format('d/m/Y') ?? '',
            'age'               => $patient->person?->birth_date
                ? Carbon::parse($patient->person->birth_date)->age . ' anos'
                : '',
            default => $this->resolveNestedField($patient, $variable->source_field),
        };
    }

    /**
     * CPF formatado (000.000.000-00). O CPF da pessoa fica em
     * `people.national_registry` — `person->cpf` não existe e deixava a
     * variável sempre vazia. Sem CPF (ou valor fora do formato), vazio.
     */
    private function patientCpf(Patient $patient): string
    {
        return BrazilianFormat::cpf($patient->person?->national_registry) ?? '';
    }

    private function resolveDoctor(ReportSettingVariable $variable, Doctor $doctor): ?string
    {
        return match ($variable->source_field) {
            'person.full_name' => $doctor->person?->full_name ?? '',
            'record'           => $doctor->record ?? '',
            'record_specialty' => $doctor->record_specialty ?? '',
            default            => $this->resolveNestedField($doctor, $variable->source_field),
        };
    }

    private function resolveEntity(ReportSettingVariable $variable, Entity $entity): ?string
    {
        return match ($variable->source_field) {
            'name'    => $entity->name ?? '',
            'address' => $entity->address ?? '',
            'phone'   => $entity->phone ?? '',
            default   => $this->resolveNestedField($entity, $variable->source_field),
        };
    }

    private function resolveDate(ReportSettingVariable $variable): string
    {
        $date = $this->documentDay();

        return match ($variable->source_field) {
            'current' => $date->format('d/m/Y'),
            'long'    => $date->isoFormat('dddd, D [de] MMMM [de] YYYY'),
            default   => $date->format('d/m/Y'),
        };
    }

    /** Data do documento: a alternativa escolhida pelo médico ou hoje. */
    private function documentDay(): CarbonInterface
    {
        return $this->documentDate ?? now();
    }

    private function resolveMedicalRecord(ReportSettingVariable $variable, ?MedicalRecord $record): ?string
    {
        if (! $record) {
            return $variable->default_value ?? '';
        }

        $field = $variable->source_field;
        $value = $field ? data_get($record, $field) : null;

        if ($value instanceof DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        if ($value !== null) {
            return (string) $value;
        }

        return $variable->default_value ?? '';
    }

    private function buildLocalDate(Entity $entity): string
    {
        $parts = array_filter([(string) ($entity->city ?? ''), (string) ($entity->state ?? '')]);
        $local = implode('/', $parts);
        $date  = $this->documentDay()->isoFormat('D [de] MMMM [de] YYYY');

        return $local !== '' ? "{$local}, {$date}" : $date;
    }

    private function resolveNestedField(object $model, ?string $field): ?string
    {
        if (! $field) {
            return null;
        }

        $value = data_get($model, $field);

        if ($value instanceof DateTimeInterface) {
            return $value->format('d/m/Y');
        }

        return $value !== null ? (string) $value : null;
    }

    /**
     * Variáveis do sistema que estão sempre disponíveis como fallback,
     * mesmo sem entrada na tabela report_setting_variables.
     */
    private function buildSystemReplacements(Patient $patient, Doctor $doctor, Entity $entity, ?MedicalRecord $medicalRecord = null): array
    {
        $patientName = $patient->person?->full_name ?? '';
        $patientAge  = $patient->person?->birth_date
            ? Carbon::parse($patient->person->birth_date)->age . ' anos'
            : '';
        $patientBirth = $patient->person?->birth_date?->format('d/m/Y') ?? '';
        $examDate     = $medicalRecord?->created_at?->format('d/m/Y') ?? now()->format('d/m/Y');

        return [
            '{{PACIENTE_NOME}}'            => $patientName,
            '{{PACIENTE_CPF}}'             => $this->patientCpf($patient),
            '{{PACIENTE_DATA_NASCIMENTO}}' => $patientBirth,
            '{{PACIENTE_IDADE}}'           => $patientAge,
            '{{MEDICO_NOME}}'              => $doctor->person?->full_name ?? '',
            '{{MEDICO_CRM}}'               => $doctor->record ?? '',
            '{{MEDICO_CRM_ESPECIALIDADE}}' => $doctor->record_specialty ?? '',
            '{{CLINICA_NOME}}'             => $entity->name ?? '',
            '{{CLINICA_ENDERECO}}'         => $entity->address ?? '',
            '{{CLINICA_TELEFONE}}'         => $entity->phone ?? '',
            '{{DATA_ATUAL}}'               => $this->documentDay()->format('d/m/Y'),
            '{{DATA_EXTENSO}}'             => $this->documentDay()->isoFormat('dddd, D [de] MMMM [de] YYYY'),
            '{{DATA_EXAME}}'               => $examDate,
            '{{LOCAL_DATA}}'               => $this->buildLocalDate($entity),
            // {{CABECALHO_PACIENTE}} resolvido como vazio: o PDF blade
            // renderiza patient-block próprio via $setting->patient_name,
            // tornando o placeholder redundante (causava duplicação no PDF
            // e poluição no editor TinyMCE com Paciente/Idade/Data).
            '{{CABECALHO_PACIENTE}}' => '',
        ];
    }
}
