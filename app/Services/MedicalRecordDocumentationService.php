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
     *
     * @param list<string>|null $categorySlugs Restringe a settings destas
     *                                         categorias (ver ReportCategory.slug) — usado pelo Gerenciador de
     *                                         Imagens, que só quer "laudos"/"exames-especializados" na lista, não
     *                                         receituários/atestados. Sem filtro (default), comportamento igual ao
     *                                         original: todos os templates ativos.
     */
    public function getActiveTemplates(string $entityId, ?array $categorySlugs = null): array
    {
        return ReportSetting::with(['contents' => fn ($q) => $q->where('active', true)])
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->where('active', true)
            ->when(
                $categorySlugs !== null,
                fn ($q) => $q->whereHas('category', fn ($c) => $c->whereIn('slug', $categorySlugs)),
            )
            // Exclui antecipadamente settings sem conteúdo ativo, para que o groupBy
            // não deixe uma cópia adotada vazia "ganhar" sobre o global que tem conteúdo.
            ->whereHas('contents', fn ($q) => $q->where('active', true))
            // Settings da entidade têm prioridade sobre globais de mesmo título.
            ->orderByRaw('CASE WHEN entity_id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('title')
            ->get()
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
     * Garante que um ReportSettingContent é global OU pertence à entity ativa
     * — content de outra clínica nunca deve ser resolvido/usado aqui (404, não
     * 403: não confirma existência do template de outra clínica).
     *
     * SEGURANÇA: ReportSetting tem EntityScope global (TenantScopeServiceProvider)
     * — pra QUALQUER outra clínica, `$content->reportSetting` (relação normal,
     * sujeita ao scope) resolve para null, não para o dono real. Confiar nisso
     * faria o guard tratar "de outra clínica" como "sem dono" (passa!) — vaza
     * o `content` de um template privado de outro tenant pra quem souber/
     * adivinhar o UUID. `withoutEntityScope()` vê o dono verdadeiro, scope ou
     * não (mantém a checagem de soft-delete intacta).
     */
    public function assertTemplateBelongsToEntity(ReportSettingContent $content, string $entityId): void
    {
        $settingEntityId = (string) (ReportSetting::query()
            ->withoutEntityScope()
            ->whereKey($content->report_setting_id)
            ->value('entity_id') ?? '');

        abort_if($settingEntityId !== '' && $settingEntityId !== $entityId, 404);
    }

    /**
     * Limpa o HTML de um template resolvido para exibição num editor livre:
     *   - remove placeholders remanescentes ({{CONTEUDO_LIVRE}} etc.) que o
     *     TemplateVariableResolver não resolve (preenchidos só via quick-actions);
     *   - remove o cabeçalho de data/local já resolvido (duplicaria o que o
     *     bloco de assinatura do PDF mostra).
     * Mesmo tratamento usado pelo editor de Documentações do prontuário —
     * reaproveitado aqui para manter os dois editores idênticos.
     */
    public function stripResolvedPlaceholders(string $html): string
    {
        $html = preg_replace('/\{\{[A-Z_][A-Z0-9_]*\}\}/u', '', $html) ?? $html;

        // <p ... text-align:right ...>QUALQUER COISA</p>(\s*<p>&nbsp;</p>)? só
        // no INÍCIO do conteúdo (no máximo após whitespace).
        $pattern = '/^\s*<p\s+[^>]*text-align\s*:\s*right[^>]*>[^<]*<\/p>\s*(?:<p[^>]*>&nbsp;<\/p>)?\s*/iu';

        return preg_replace($pattern, '', $html, 1) ?? $html;
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
