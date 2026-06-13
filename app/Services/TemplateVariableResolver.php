<?php

namespace App\Services;

use App\Models\{Doctor, Entity, MedicalRecord, Patient, ReportSettingContent, ReportSettingVariable};
use Carbon\Carbon;
use DateTimeInterface;

/**
 * Resolve variáveis dinâmicas em templates de documentação clínica.
 *
 * Fontes suportadas:
 *   - patient      → Person do paciente (full_name, cpf, birth_date, age)
 *   - doctor       → Person do médico (full_name, record, record_specialty)
 *   - entity       → Dados da clínica (name, address, phone)
 *   - medical_record → Campos clínicos do prontuário (tonometria, refração, etc.)
 *   - date         → Data atual em diferentes formatos
 *   - custom       → Valor default ou campo em branco para preenchimento manual
 */
class TemplateVariableResolver
{
    /**
     * Resolve o conteúdo de um template substituindo todas as variáveis.
     *
     * @return array{html: string, unresolved: array<string, string>}
     */
    public function resolve(
        ReportSettingContent $content,
        Patient $patient,
        Doctor $doctor,
        Entity $entity,
        ?MedicalRecord $medicalRecord = null,
    ): array {
        $patient->loadMissing('person');
        $doctor->loadMissing('person');

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
            'person.cpf'        => $patient->person?->cpf ?? '',
            'person.birth_date' => $patient->person?->birth_date?->format('d/m/Y') ?? '',
            'age'               => $patient->person?->birth_date
                ? Carbon::parse($patient->person->birth_date)->age . ' anos'
                : '',
            default => $this->resolveNestedField($patient, $variable->source_field),
        };
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
        return match ($variable->source_field) {
            'current' => now()->format('d/m/Y'),
            'long'    => now()->isoFormat('dddd, D [de] MMMM [de] YYYY'),
            default   => now()->format('d/m/Y'),
        };
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
        $date  = now()->isoFormat('D [de] MMMM [de] YYYY');

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
            '{{PACIENTE_CPF}}'             => $patient->person?->cpf ?? '',
            '{{PACIENTE_DATA_NASCIMENTO}}' => $patientBirth,
            '{{PACIENTE_IDADE}}'           => $patientAge,
            '{{MEDICO_NOME}}'              => $doctor->person?->full_name ?? '',
            '{{MEDICO_CRM}}'               => $doctor->record ?? '',
            '{{MEDICO_CRM_ESPECIALIDADE}}' => $doctor->record_specialty ?? '',
            '{{CLINICA_NOME}}'             => $entity->name ?? '',
            '{{CLINICA_ENDERECO}}'         => $entity->address ?? '',
            '{{CLINICA_TELEFONE}}'         => $entity->phone ?? '',
            '{{DATA_ATUAL}}'               => now()->format('d/m/Y'),
            '{{DATA_EXTENSO}}'             => now()->isoFormat('dddd, D [de] MMMM [de] YYYY'),
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
