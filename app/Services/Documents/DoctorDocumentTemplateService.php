<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Enums\DocumentationType;
use App\Models\{Doctor, DoctorDocumentTemplate, Entity, Indication, MedicalRecord, Medicine, Procedure, ReportSetting, ReportSettingContent};
use App\Services\{MedicalRecordQuickActionService, ProcedureSolicitationService};
use App\Support\Documents\DocumentDate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mews\Purifier\Facades\Purifier;

/**
 * Modelos PESSOAIS do médico (Fase 2 / B3 — "Salvar como modelo").
 *
 *  - Por clínica e por médico (o médico que emite — CurrentDoctor::issuer):
 *    só o dono lista, usa, edita e exclui (outro médico/clínica → 404).
 *  - Receita e pedido (modais do prontuário): corpo em texto + itens
 *    estruturados; a base é o modelo da clínica da própria ação.
 *  - Relatório/documento livre (editor de Documentações): corpo HTML
 *    sanitizado; a base é o modelo da clínica escolhido no seletor.
 *  - Nome único por médico e tipo (sem diferenciar maiúsculas) — tipo REAL,
 *    resolvido pelo modelo-base antes de conferir nome e limite.
 *  - Formato: `text` (modais de receita/pedido — corpo em texto + itens) ×
 *    `html` (editor de Documentações). Os modais só listam/usam `text`; o
 *    editor lista os dois (o texto vira HTML na prévia).
 *  - LGPD: modelo reutilizável não guarda dado de paciente — o HTML do
 *    editor volta às variáveis com o mapa do prontuário de origem
 *    (PersonalTemplateDeidentifier); sobrou nome/CPF/código → recusa.
 */
class DoctorDocumentTemplateService
{
    public function __construct(
        private readonly MedicalRecordQuickActionService $quickActions,
        private readonly PersonalTemplateDeidentifier $deidentifier,
    ) {
    }

    /**
     * @param string|null $format `text` = só os que os modais entendem (corpo
     *                            em texto + itens); `html` = só os do editor;
     *                            null = todos (editor de Documentações)
     *
     * @return Collection<int, DoctorDocumentTemplate>
     */
    public function list(string $entityId, Doctor $doctor, ?string $kind = null, ?string $format = null): Collection
    {
        return $this->query($entityId, $doctor)
            ->when($kind !== null, fn ($q) => $q->where('kind', $kind))
            ->when($format !== null, fn ($q) => $q->where('body_format', $format))
            ->orderBy('name')
            ->get();
    }

    /**
     * Opções do seletor de modelos do editor de Documentações: os pessoais
     * cujo modelo-base da clínica segue ativo.
     *
     * @return list<array<string, mixed>>
     */
    public function forSelector(string $entityId, ?Doctor $doctor): array
    {
        if ($doctor === null) {
            return [];
        }

        $templates = $this->query($entityId, $doctor)->whereNotNull('report_setting_content_id')->orderBy('name')->get();
        $bases     = $this->activeBases($entityId, $templates->pluck('report_setting_content_id')->filter()->unique()->values()->all());

        return $templates
            ->filter(fn (DoctorDocumentTemplate $t) => $bases->has((string) $t->report_setting_content_id))
            ->map(fn (DoctorDocumentTemplate $t) => [
                'id'          => 'tpl:' . $t->id,
                'template_id' => (string) $t->id,
                'base_id'     => (string) $t->report_setting_content_id,
                'type'        => $bases->get((string) $t->report_setting_content_id)?->type?->value,
                'label'       => $t->name,
                'personal'    => true,
                // Itens do catálogo (receita): "Salvar como modelo" a partir
                // do editor de Documentações reaproveita, senão o vínculo com
                // o catálogo se perde de novo a cada modelo derivado de outro
                // (3ª revisão Codex, P1).
                'items' => $t->kind === 'prescription' ? ($t->payload['items'] ?? []) : null,
            ])
            ->values()
            ->all();
    }

    public function ownedOrFail(string $entityId, ?Doctor $doctor, string $id): DoctorDocumentTemplate
    {
        abort_if($doctor === null, 404);

        return $this->query($entityId, $doctor)->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $data kind, name, body, payload, report_setting_content_id,
     *                                   medical_record_id (prontuário de onde o texto veio), document_date
     */
    public function create(string $entityId, Doctor $doctor, array $data): DoctorDocumentTemplate
    {
        // Tipo REAL primeiro: o editor manda "report" mesmo quando o modelo-base
        // é uma receita/pedido — nome e limite se conferem no tipo gravado.
        [$base, $format, $kind] = $this->base($entityId, (string) $data['kind'], $data['report_setting_content_id'] ?? null);

        if ($this->query($entityId, $doctor)->where('kind', $kind)->count() >= (int) config('clinical_documents.personal_templates.max_per_kind', 100)) {
            throw ValidationException::withMessages(['name' => __('clinical_documents.templates.limit', ['max' => config('clinical_documents.personal_templates.max_per_kind', 100)])]);
        }

        $this->assertUniqueName($entityId, $doctor, $kind, (string) $data['name']);

        $record = $this->requiredRecord($entityId, $data);

        // Nome do modelo também não pode levar dado do paciente (9ª revisão
        // Codex, P2): só corpo e payload passavam por isto. Um nome como
        // "Maria Silva — pós-op" vazava o nome (ou CPF) de outro paciente ao
        // reaplicar o modelo depois — ele sai impresso no PDF (docForm.title).
        $this->deidentifier->assertCleanFields(['name' => [__('clinical_documents.templates.name'), (string) $data['name']]], $record);

        $template = new DoctorDocumentTemplate([
            'kind'                      => $kind,
            'report_setting_content_id' => $base->id,
            'name'                      => trim((string) $data['name']),
            'body'                      => $this->cleanBody($entityId, $doctor, $base, $format, (string) $data['body'], $data, $record),
            'body_format'               => $format,
            'payload'                   => $this->cleanPayload($entityId, $kind, $data['payload'] ?? null, $record),
        ]);
        $template->entity_id = $entityId;
        $template->doctor_id = $doctor->id;
        $template->save();

        return $template;
    }

    /** @param array<string, mixed> $data name?, body?, payload? */
    public function update(DoctorDocumentTemplate $template, array $data): DoctorDocumentTemplate
    {
        // Conteúdo novo (corpo, itens OU nome) só a partir de um atendimento:
        // é o prontuário de origem que diz o que é dado do paciente. Nome
        // entrou aqui também (9ª revisão Codex, P2) — ver create().
        $record = array_key_exists('body', $data) || array_key_exists('payload', $data) || array_key_exists('name', $data)
            ? $this->requiredRecord((string) $template->entity_id, $data)
            : null;

        if (array_key_exists('name', $data)) {
            $this->assertUniqueName((string) $template->entity_id, $template->doctor()->firstOrFail(), $template->kind, (string) $data['name'], (string) $template->id);
            $this->deidentifier->assertCleanFields(['name' => [__('clinical_documents.templates.name'), (string) $data['name']]], $record);
            $template->name = trim((string) $data['name']);
        }

        if (array_key_exists('body', $data)) {
            $doctor = $template->doctor()->withoutGlobalScopes()->firstOrFail();
            // Base excluída depois: segue sem a preferência de variável dela.
            $base = ReportSettingContent::withTrashed()->find($template->report_setting_content_id) ?? new ReportSettingContent();

            $template->body = $this->cleanBody((string) $template->entity_id, $doctor, $base, $template->body_format, (string) $data['body'], $data, $record);
        }

        if (array_key_exists('payload', $data)) {
            $template->payload = $this->cleanPayload((string) $template->entity_id, $template->kind, $data['payload'], $record);
        }

        $template->save();

        return $template;
    }

    /** @return array<string, mixed> */
    public function serialize(DoctorDocumentTemplate $template): array
    {
        return [
            'id'                        => (string) $template->id,
            'kind'                      => $template->kind,
            'name'                      => $template->name,
            'body'                      => $template->body,
            'body_format'               => $template->body_format,
            'payload'                   => $template->payload ?? (object) [],
            'report_setting_content_id' => $template->report_setting_content_id ? (string) $template->report_setting_content_id : null,
            'updated_at_label'          => $template->updated_at?->copy()->locale(app()->getLocale())->isoFormat('L LT'),
        ];
    }

    // ── Internos ────────────────────────────────────────────────────────────

    private function query(string $entityId, Doctor $doctor)
    {
        return DoctorDocumentTemplate::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->ownedBy($entityId, (string) $doctor->id);
    }

    private function assertUniqueName(string $entityId, Doctor $doctor, string $kind, string $name, ?string $exceptId = null): void
    {
        $exists = $this->query($entityId, $doctor)
            ->where('kind', $kind)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['name' => __('clinical_documents.templates.duplicate')]);
        }
    }

    /**
     * Modelo-base da clínica: receita/pedido usam o modelo da ação; o editor
     * de Documentações informa o modelo escolhido (da clínica ou global,
     * ativo) — e o tipo dele decide o `kind`.
     *
     * @return array{0: ReportSettingContent, 1: string, 2: string}
     */
    private function base(string $entityId, string $kind, mixed $contentId): array
    {
        if (is_string($contentId) && $contentId !== '') {
            $content = $this->activeBases($entityId, [$contentId])->get($contentId);

            if ($content === null) {
                throw ValidationException::withMessages(['report_setting_content_id' => __('clinical_documents.templates.base_invalid')]);
            }

            $resolvedKind = match ($content->type) {
                DocumentationType::Prescription => 'prescription',
                DocumentationType::Procedure    => 'procedure',
                default                         => 'report',
            };

            return [$content, 'html', $resolvedKind];
        }

        $action = match ($kind) {
            'prescription' => 'medication-prescription',
            'procedure'    => 'procedure-request',
            default        => throw ValidationException::withMessages(['report_setting_content_id' => __('clinical_documents.templates.base_invalid')]),
        };

        try {
            return [$this->quickActions->templateForAction($entityId, $action), 'text', $kind];
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['kind' => __('clinical_documents.templates.base_invalid')]);
        }
    }

    /**
     * Modelos-base ATIVOS visíveis à clínica (dela ou globais), por id.
     *
     * @param list<string> $ids
     *
     * @return Collection<string, ReportSettingContent>
     */
    private function activeBases(string $entityId, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $settings = ReportSetting::query()
            ->withoutEntityScope()
            ->where('active', true)
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->pluck('id');

        return ReportSettingContent::query()
            ->whereIn('id', $ids)
            ->where('active', true)
            ->whereIn('report_setting_id', $settings)
            ->get()
            ->keyBy(fn (ReportSettingContent $c) => (string) $c->id);
    }

    /**
     * Corpo sanitizado e SEM dado de paciente: HTML do editor volta às
     * variáveis pelo prontuário de origem; texto dos modais é conferido
     * (com dado do paciente, recusa).
     *
     * @param array<string, mixed> $data medical_record_id, document_date
     */
    private function cleanBody(string $entityId, Doctor $doctor, ReportSettingContent $base, string $format, string $body, array $data, MedicalRecord $record): string
    {
        $body = $this->body($format, $body);

        if ($format === 'html') {
            return $this->deidentifier->deidentify(
                $body,
                $base,
                $record,
                $doctor,
                Entity::query()->findOrFail($entityId),
                DocumentDate::alternative(is_string($data['document_date'] ?? null) ? $data['document_date'] : null),
            );
        }

        $this->deidentifier->assertClean($body, $record);

        return $body;
    }

    /**
     * Itens estruturados conferidos no catálogo E sem dado do paciente em
     * nenhum campo de texto livre — receita (nome digitado, princípio ativo)
     * e pedido (exame digitado, indicação livre, observações) — 2ª revisão,
     * P1-A. Varre o payload inteiro: campo texto novo entra sozinho.
     */
    private function cleanPayload(string $entityId, string $kind, mixed $payload, MedicalRecord $record): ?array
    {
        $payload = $this->payload($entityId, $kind, $payload);

        if ($payload !== null) {
            $this->deidentifier->assertCleanFields($this->payloadTexts($payload), $record);
        }

        return $payload;
    }

    /**
     * Folhas de texto do payload (ids do catálogo ficam de fora).
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, array{0: string, 1: string}> "payload.items.0.name" → [rótulo, texto]
     */
    private function payloadTexts(array $payload, string $prefix = 'payload'): array
    {
        $out = [];

        foreach ($payload as $key => $value) {
            $path = "{$prefix}.{$key}";

            if (is_array($value)) {
                $out = [...$out, ...$this->payloadTexts($value, $path)];

                continue;
            }

            if (! is_string($value) || trim($value) === '' || in_array($key, ['id', 'medicine_id', 'procedure_id', 'type'], true)) {
                continue;
            }

            $out[$path] = [$this->fieldLabel($path), $value];
        }

        return $out;
    }

    private function fieldLabel(string $path): string
    {
        if (preg_match('/^payload\.items\.(\d+)\.(\w+)$/', $path, $m) === 1) {
            return __('clinical_documents.templates.fields.item', [
                'n'     => (int) $m[1] + 1,
                'field' => __('clinical_documents.templates.fields.' . $m[2]),
            ]);
        }

        $key = (string) preg_replace('/^.*\./', '', $path);
        $tr  = __('clinical_documents.templates.fields.' . $key);

        return is_string($tr) && ! str_starts_with($tr, 'clinical_documents.') ? $tr : $key;
    }

    /**
     * Prontuário de origem obrigatório para gravar conteúdo de modelo: é ele
     * que diz o que é dado do paciente (todo "Salvar como modelo" nasce num
     * atendimento).
     *
     * @param array<string, mixed> $data
     */
    private function requiredRecord(string $entityId, array $data): MedicalRecord
    {
        return $this->contextRecord($entityId, $data['medical_record_id'] ?? null)
            ?? throw ValidationException::withMessages(['medical_record_id' => __('clinical_documents.templates.record_required')]);
    }

    /** Prontuário desta clínica (de outra → recusado, sem dizer se existe). */
    private function contextRecord(string $entityId, mixed $id): ?MedicalRecord
    {
        if (! is_string($id) || $id === '') {
            return null;
        }

        $record = MedicalRecord::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->find($id);

        if ($record === null) {
            throw ValidationException::withMessages(['medical_record_id' => __('clinical_documents.templates.record_required')]);
        }

        return $record;
    }

    private function body(string $format, string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages(['body' => __('clinical_documents.templates.body_required')]);
        }

        // HTML do editor: mesma sanitização da Documentação (profile medical).
        return $format === 'html' ? Purifier::clean($body, 'medical') : $body;
    }

    /**
     * Itens estruturados conferidos no servidor (catálogo desta clínica).
     *
     * @return array<string, mixed>|null
     */
    private function payload(string $entityId, string $kind, mixed $payload): ?array
    {
        if (! is_array($payload) || $payload === []) {
            return null;
        }

        return match ($kind) {
            'prescription' => ['items' => $this->prescriptionItems($entityId, $payload['items'] ?? [])],
            'procedure'    => $this->procedurePayload($entityId, $payload),
            default        => null,
        };
    }

    /**
     * @return list<array{medicine_id: ?string, name: string, active_ingredient: ?string}>
     */
    private function prescriptionItems(string $entityId, mixed $items): array
    {
        $items = is_array($items) ? array_slice(array_values(array_filter($items, 'is_array')), 0, 30) : [];
        $ids   = collect($items)->pluck('medicine_id')->filter(fn ($id) => is_string($id) && $id !== '')->unique()->values()->all();

        $catalog = $ids === [] ? collect() : Medicine::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId))
            ->get(['id', 'name', 'active_ingredient'])
            ->keyBy(fn (Medicine $m) => (string) $m->id);

        return array_values(array_filter(array_map(function (array $item) use ($catalog) {
            $medicine = is_string($item['medicine_id'] ?? null) ? $catalog->get($item['medicine_id']) : null;
            $name     = trim((string) ($item['name'] ?? $medicine?->name ?? ''));

            return $name === '' ? null : [
                'medicine_id'       => $medicine ? (string) $medicine->id : null,
                'name'              => mb_substr($name, 0, 255),
                'active_ingredient' => $medicine?->active_ingredient ?? (filled($item['active_ingredient'] ?? null) ? mb_substr(trim((string) $item['active_ingredient']), 0, 1000) : null),
            ];
        }, $items)));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function procedurePayload(string $entityId, array $payload): array
    {
        $items = is_array($payload['items'] ?? null)
            ? array_slice(array_values(array_filter($payload['items'], 'is_array')), 0, ProcedureSolicitationService::MAX_PROCEDURES)
            : [];

        $procedureIds = collect($items)->pluck('procedure_id')->filter(fn ($id) => is_string($id) && $id !== '')->unique()->values()->all();
        $procedures   = $procedureIds === [] ? collect() : Procedure::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', $procedureIds)
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId))
            ->get(['id', 'code', 'name'])
            ->keyBy(fn (Procedure $p) => (string) $p->id);

        $indicationIds = is_array($payload['indications'] ?? null)
            ? array_slice(array_values(array_filter(array_map(fn ($i) => is_array($i) ? ($i['id'] ?? null) : $i, $payload['indications']), 'is_string')), 0, ProcedureSolicitationService::MAX_INDICATIONS)
            : [];
        $indications = $indicationIds === [] ? collect() : Indication::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('id', $indicationIds)
            ->where(fn ($q) => $q->whereNull('entity_id')->orWhere('entity_id', $entityId))
            ->get(['id', 'description']);

        $type = is_string($payload['type'] ?? null) && in_array($payload['type'], ProcedureSolicitationService::typeKeys(), true) ? $payload['type'] : null;

        return [
            'items' => array_values(array_filter(array_map(function (array $item) use ($procedures) {
                $procedure = is_string($item['procedure_id'] ?? null) ? $procedures->get($item['procedure_id']) : null;
                $name      = trim((string) ($procedure?->name ?? $item['name'] ?? ''));

                return $name === '' ? null : [
                    'procedure_id' => $procedure ? (string) $procedure->id : null,
                    'code'         => $procedure?->code ?? (filled($item['code'] ?? null) ? mb_substr(trim((string) $item['code']), 0, 10) : null),
                    'name'         => mb_substr($name, 0, 160),
                ];
            }, $items))),
            'type'            => $type,
            'indications'     => $indications->map(fn (Indication $i) => ['id' => (string) $i->id, 'description' => $i->description])->values()->all(),
            'indication_text' => filled($payload['indication_text'] ?? null) ? mb_substr(trim((string) $payload['indication_text']), 0, 500) : null,
            'notes'           => filled($payload['notes'] ?? null) ? mb_substr(trim((string) $payload['notes']), 0, 2000) : null,
        ];
    }
}
