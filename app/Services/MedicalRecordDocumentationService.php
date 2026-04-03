<?php

namespace App\Services;

use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, ReportSetting, ReportSettingContent};
use Carbon\Carbon;

/**
 * Gerencia a criação de documentações clínicas (receituários, solicitações,
 * atestados, encaminhamentos) e a substituição de variáveis dinâmicas nos templates.
 */
class MedicalRecordDocumentationService
{
    /**
     * Lista os tipos de documentação disponíveis com labels localizados.
     */
    public function getTypes(): array
    {
        return [
            'prescription' => __('actions.documentation.types.prescription'),
            'procedure'    => __('actions.documentation.types.procedure'),
            'certificate'  => __('actions.documentation.types.certificate'),
            'referral'     => __('actions.documentation.types.referral'),
            'report'       => __('actions.documentation.types.report'),
        ];
    }

    /**
     * Templates ativos da entidade atual, agrupados por tipo.
     */
    public function getActiveTemplates(string $entityId): array
    {
        return ReportSetting::with(['contents' => fn ($q) => $q->where('active', true)])
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->orderBy('title')
            ->get()
            ->mapWithKeys(fn ($setting) => [
                $setting->id => [
                    'title'    => $setting->title,
                    'contents' => $setting->contents->map(fn ($c) => [
                        'id'    => $c->id,
                        'type'  => $c->type,
                        'label' => $c->label ?? ucfirst($c->type),
                    ])->values(),
                ],
            ])
            ->toArray();
    }

    /**
     * Carrega o conteúdo de um template e substitui as variáveis dinâmicas.
     *
     * Variáveis suportadas:
     *   {{PACIENTE_NOME}}, {{PACIENTE_CPF}}, {{PACIENTE_DATA_NASCIMENTO}}
     *   {{MEDICO_NOME}}, {{MEDICO_CRM}}, {{MEDICO_CRM_ESPECIALIDADE}}
     *   {{CLINICA_NOME}}, {{CLINICA_ENDERECO}}, {{CLINICA_TELEFONE}}
     *   {{DATA_ATUAL}}, {{DATA_EXTENSO}}
     */
    public function loadTemplate(
        ReportSettingContent $content,
        Patient $patient,
        Doctor $doctor,
        Entity $entity
    ): string {
        $patient->loadMissing('person');
        $doctor->loadMissing('person');

        $replacements = [
            '{{PACIENTE_NOME}}'            => $patient->person?->full_name ?? '',
            '{{PACIENTE_CPF}}'             => $patient->person?->cpf ?? '',
            '{{PACIENTE_DATA_NASCIMENTO}}' => $patient->person?->birth_date?->format('d/m/Y') ?? '',
            '{{PACIENTE_IDADE}}'           => $patient->person?->birth_date
                ? Carbon::parse($patient->person->birth_date)->age . ' anos'
                : '',
            '{{MEDICO_NOME}}'              => $doctor->person?->full_name ?? '',
            '{{MEDICO_CRM}}'               => $doctor->record ?? '',
            '{{MEDICO_CRM_ESPECIALIDADE}}' => $doctor->record_specialty ?? '',
            '{{CLINICA_NOME}}'             => $entity->name ?? '',
            '{{CLINICA_ENDERECO}}'         => $entity->address ?? '',
            '{{CLINICA_TELEFONE}}'         => $entity->phone ?? '',
            '{{DATA_ATUAL}}'               => now()->format('d/m/Y'),
            '{{DATA_EXTENSO}}'             => now()->isoFormat('dddd, D [de] MMMM [de] YYYY'),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content->content
        );
    }

    /**
     * Cria uma documentação clínica com conteúdo final (variáveis já substituídas).
     */
    public function store(
        MedicalRecord $record,
        ReportSettingContent $content,
        string $resolvedContent,
        ?string $title = null
    ): MedicalRecordDocumentation {
        return MedicalRecordDocumentation::create([
            'medical_record_id'         => $record->id,
            'patient_id'                => $record->patient_id,
            'doctor_id'                 => $record->doctor_id,
            'report_setting_id'         => $content->report_setting_id,
            'report_setting_content_id' => $content->id,
            'type'                      => $content->type,
            'title'                     => $title ?? ($content->label ?? ucfirst($content->type)),
            'content'                   => $resolvedContent,
        ]);
    }
}
