<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setting;

use App\Enums\Surgery\SurgeryDocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Surgery\SurgeryTemplateRequest;
use App\Models\Surgery\{SurgeryClinicSetting, SurgeryConsentTemplate};
use App\Models\SurgeryType;
use App\Services\Surgery\Documents\{SurgeryTemplateService, SurgeryTemplateVariables};
use App\Support\Surgery\SurgeryLang;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Configurações > Modelos cirúrgicos: TCLE e orientações pré-operatórias da
 * clínica, adoção dos modelos padrão e o aviso de autorização por WhatsApp.
 * Rota atrás de `permission:settings.manage` + `feature:has_surgery`; binding
 * do modelo sempre na clínica da sessão (SurgeryConsentTemplate).
 */
class SurgeryTemplatesController extends Controller
{
    public function __construct(
        private readonly SurgeryTemplateService $templates,
        private readonly SurgeryTemplateVariables $variables,
    ) {
    }

    public function index(): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');

        $rows = SurgeryConsentTemplate::query()
            ->where('entity_id', $entityId)
            ->orderBy('type')
            ->orderByRaw('category nulls first')
            ->orderBy('name')
            ->get()
            ->map(fn (SurgeryConsentTemplate $t) => $this->row($t))
            ->all();

        return Inertia::render('Panel/Settings/SurgeryTemplates/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('surgery_documents.templates.title'), 'url' => '#', 'active' => true],
            ],
            'templates'       => $rows,
            'types'           => array_map(fn (SurgeryDocumentType $t) => ['value' => $t->value, 'label' => $t->label()], SurgeryConsentTemplate::TYPES),
            'categories'      => collect(SurgeryType::$categories)->map(fn (string $name, int $id) => ['value' => $id, 'label' => $name])->values()->all(),
            'variables'       => array_map(fn (string $key) => ['key' => $key, 'label' => (string) __("surgery_documents.variables.{$key}"), 'token' => '{{' . $key . '}}'], SurgeryTemplateVariables::KEYS),
            'missingDefaults' => $this->templates->missingDefaults($entityId),
            'notices'         => ['authorization_enabled' => SurgeryClinicSetting::authorizationNoticeEnabled($entityId)],
            'urls'            => [
                'store'     => route('panel.setting.surgery-templates.store'),
                'show'      => route('panel.setting.surgery-templates.show', ['template' => '__ID__']),
                'update'    => route('panel.setting.surgery-templates.update', ['template' => '__ID__']),
                'destroy'   => route('panel.setting.surgery-templates.destroy', ['template' => '__ID__']),
                'duplicate' => route('panel.setting.surgery-templates.duplicate', ['template' => '__ID__']),
                'active'    => route('panel.setting.surgery-templates.active', ['template' => '__ID__']),
                'preview'   => route('panel.setting.surgery-templates.preview'),
                'adopt'     => route('panel.setting.surgery-templates.adopt-defaults'),
                'notices'   => route('panel.setting.surgery-templates.notices'),
            ],
            't' => SurgeryLang::all(),
        ]);
    }

    /** Modelo inteiro (com o corpo) para o editor. */
    public function show(SurgeryConsentTemplate $template): JsonResponse
    {
        return response()->json([...$this->row($template), 'body' => $template->body]);
    }

    public function store(SurgeryTemplateRequest $request): RedirectResponse
    {
        $this->templates->create((string) session('selected_entity_id'), $request->validated());

        return back()->with('success', __('surgery_documents.templates.created'));
    }

    public function update(SurgeryTemplateRequest $request, SurgeryConsentTemplate $template): RedirectResponse
    {
        $this->templates->update($template, $request->validated());

        return back()->with('success', __('surgery_documents.templates.updated'));
    }

    public function destroy(SurgeryConsentTemplate $template): RedirectResponse
    {
        $this->templates->delete($template);

        return back()->with('success', __('surgery_documents.templates.deleted'));
    }

    public function duplicate(SurgeryConsentTemplate $template): RedirectResponse
    {
        $this->templates->duplicate($template);

        return back()->with('success', __('surgery_documents.templates.duplicated'));
    }

    public function setActive(Request $request, SurgeryConsentTemplate $template): RedirectResponse
    {
        $data = $request->validate(['active' => ['required', 'boolean']]);

        $this->templates->setActive($template, (bool) $data['active']);

        return back()->with('success', __($data['active'] ? 'surgery_documents.templates.activated' : 'surgery_documents.templates.deactivated'));
    }

    /** Prévia do corpo (ainda não salvo) com dados de exemplo — o HTML sai sanitizado. */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:200000']]);

        return response()->json($this->templates->preview($data['body']));
    }

    public function adoptDefaults(Request $request): RedirectResponse
    {
        $result = $this->templates->adoptDefaults((string) session('selected_entity_id'), app()->getLocale());

        return back()->with('success', __('surgery_documents.templates.adopted', $result));
    }

    /** Liga/desliga o aviso neutro de autorização por WhatsApp (desligado de fábrica). */
    public function updateNotices(Request $request): RedirectResponse
    {
        $data = $request->validate(['authorization_enabled' => ['required', 'boolean']]);

        SurgeryClinicSetting::query()->updateOrCreate(
            ['entity_id' => (string) session('selected_entity_id')],
            ['authorization_notice_enabled' => (bool) $data['authorization_enabled']],
        );

        return back()->with('success', __('surgery_documents.notices.saved'));
    }

    /** @return array<string, mixed> */
    private function row(SurgeryConsentTemplate $t): array
    {
        return [
            'id'             => (string) $t->getKey(),
            'type'           => $t->type->value,
            'type_label'     => $t->type->label(),
            'category'       => $t->category,
            'category_label' => $t->categoryLabel(),
            'name'           => $t->name,
            'version'        => (int) $t->version,
            'active'         => (bool) $t->active,
            'is_default'     => $t->default_key !== null,
            'updated_at'     => $t->updated_at?->toIso8601String(),
        ];
    }
}
