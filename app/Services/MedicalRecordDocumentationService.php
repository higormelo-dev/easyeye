<?php

namespace App\Services;

use App\Enums\DocumentationType;
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, ReportSetting, ReportSettingContent};

/**
 * Gerencia a criação de documentações clínicas (receituários, solicitações,
 * atestados, encaminhamentos) e a substituição de variáveis dinâmicas nos templates.
 */
class MedicalRecordDocumentationService
{
    public function __construct(
        private readonly TemplateVariableResolver $variableResolver,
    ) {
    }

    /**
     * Lista os tipos de documentação disponíveis com labels localizados.
     */
    public function getTypes(): array
    {
        return collect(DocumentationType::cases())
            ->reject(fn ($t) => $t === DocumentationType::Tonometry)
            ->mapWithKeys(fn ($t) => [$t->value => $t->label()])
            ->all();
    }

    /**
     * Templates ativos da entidade atual (próprios + adotados).
     */
    public function getActiveTemplates(string $entityId): array
    {
        return ReportSetting::with(['contents' => fn ($q) => $q->where('active', true)])
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->orderByRaw('CASE WHEN entity_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('title')
            ->get()
            // Se existir versão da entidade, prioriza sobre template global do mesmo título.
            ->groupBy('title')
            ->map(fn ($group) => $group->first())
            ->map(fn ($setting) => [
                'report_setting_id'    => $setting->id,
                'report_setting_title' => $setting->title,
                'contents'             => $setting->contents->map(fn ($c) => [
                    'id'    => $c->id,
                    'type'  => $c->type?->value ?? (string) $c->type,
                    'label' => $c->display_label,
                ])->values()->all(),
            ])
            ->filter(fn (array $group) => ! empty($group['contents']))
            ->values()
            ->toArray();
    }

    /**
     * Carrega o conteúdo de um template e substitui as variáveis dinâmicas.
     *
     * Usa TemplateVariableResolver para resolução via tabela + fallback do sistema.
     *
     * @return array{html: string, unresolved: array<string, string>}
     */
    public function loadTemplate(
        ReportSettingContent $content,
        Patient $patient,
        Doctor $doctor,
        Entity $entity,
        ?MedicalRecord $medicalRecord = null,
    ): array {
        return $this->variableResolver->resolve($content, $patient, $doctor, $entity, $medicalRecord);
    }

    /**
     * Cria uma documentação clínica com conteúdo final (variáveis já substituídas).
     */
    public function store(
        MedicalRecord $record,
        ReportSettingContent $content,
        string $resolvedContent,
        ?string $title = null,
    ): MedicalRecordDocumentation {
        $setting = $content->reportSetting;

        return MedicalRecordDocumentation::create([
            'medical_record_id'         => $record->id,
            'patient_id'                => $record->patient_id,
            'doctor_id'                 => $record->doctor_id,
            'report_setting_id'         => $content->report_setting_id,
            'report_setting_content_id' => $content->id,
            'template_version'          => $setting?->version,
            'type'                      => $content->type,
            'title'                     => $title ?? ($content->display_label ?? ucfirst($content->type)),
            'content'                   => $resolvedContent,
        ]);
    }
}
