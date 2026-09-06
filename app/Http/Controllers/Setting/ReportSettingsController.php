<?php

namespace App\Http\Controllers\Setting;

use App\DTOs\ActionPolicy;
use App\Enums\{DocumentationType, PaperSize, ReportSettingStatus};
use App\Http\Controllers\Controller;
use App\Models\{ReportCategory, ReportSetting};
use App\Services\ReportSettingService;
use BackedEnum;
use Illuminate\Contracts\View\View;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

class ReportSettingsController extends Controller
{
    public function __construct(
        private readonly ReportSettingService $service,
    ) {
    }

    public function index(): InertiaResponse
    {
        $entityId = (string) session('selected_entity_id');
        $this->service->adoptPublishedGlobalsForEntity($entityId);

        $categories = ReportCategory::active()->ordered()->get(['id', 'name']);

        $records = ReportSetting::forEntity($entityId)
            ->with('category')
            ->orderBy('title')
            ->get();

        return Inertia::render('Panel/Settings/ReportSettings/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.settings'), 'url' => '#', 'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => '#', 'active' => true],
            ],
            'categories' => $categories,
            'items'      => $records->map(fn (ReportSetting $r) => array_merge([
                'id'             => (string) $r->id,
                'title'          => $r->title,
                'description'    => $r->description,
                'paper_size'     => $r->paper_size instanceof BackedEnum ? $r->paper_size->value : (string) $r->paper_size,
                'show_header'    => (bool) $r->show_header,
                'show_signature' => (bool) $r->show_signature,
                'show_footer'    => (bool) $r->show_footer,
                'is_adopted'     => $r->isAdopted(),
                'has_update'     => $r->hasUpdateAvailable(),
                'source_version' => $r->source_version,
                'category'       => $r->category?->name,
                'preview_url'    => route('panel.setting.report-settings.preview', $r),
                'edit_url'       => route('panel.setting.report-settings.edit', $r),
                'destroy_url'    => route('panel.setting.report-settings.destroy', $r),
                'reimport_url'   => $r->isAdopted() ? route('panel.setting.report-settings.reimport', $r) : null,
            ], ActionPolicy::from($r, $entityId)->toArray())),
            'urls' => [
                'create' => route('panel.setting.report-settings.create'),
            ],
        ]);
    }

    /**
     * Cards endpoint for the view toggle.
     */
    public function cards(Request $request): JsonResponse
    {
        $entityId   = session('selected_entity_id');
        $search     = $request->string('search')->trim()->value();
        $categoryId = $request->string('category_id')->trim()->value();
        $perPage    = 12;

        $records = ReportSetting::forEntity($entityId)
            ->when($search, fn ($q) => $q->whereLikeUnaccent('title', $search))
            ->when($categoryId, fn ($q) => $q->where('report_category_id', $categoryId))
            ->with('category')
            ->orderBy('title')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => [
                'id'             => $r->id,
                'title'          => $r->title,
                'paper_size'     => $r->paper_size,
                'show_header'    => $r->show_header,
                'show_signature' => $r->show_signature,
                'show_footer'    => $r->show_footer,
                'is_adopted'     => $r->isAdopted(),
                'has_update'     => $r->hasUpdateAvailable(),
                'source_version' => $r->source_version,
                'category'       => $r->category?->name,
                'preview_url'    => route('panel.setting.report-settings.preview', $r),
                'edit_url'       => route('panel.setting.report-settings.edit', $r),
                'delete_url'     => route('panel.setting.report-settings.destroy', $r),
                'reimport_url'   => $r->isAdopted() ? route('panel.setting.report-settings.reimport', $r) : null,
                ...ActionPolicy::from($r, $entityId)->toArray(),
            ]),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function create(): InertiaResponse
    {
        $categories = ReportCategory::active()->ordered()->get(['id', 'name']);

        return Inertia::render('Panel/Settings/ReportSettings/Form', [
            'breadcrumbs'         => $this->buildBreadcrumbs(__('actions.report_settings.create')),
            'mode'                => 'create',
            'reportSetting'       => null,
            'categories'          => $categories,
            'paper_sizes'         => array_map(fn (string $v) => ['value' => $v, 'label' => $v], PaperSize::values()),
            'documentation_types' => array_map(fn (string $v) => ['value' => $v, 'label' => $v], DocumentationType::values()),
            'urls'                => [
                'store' => route('panel.setting.report-settings.store'),
                'index' => route('panel.setting.report-settings.index'),
            ],
        ]);
    }

    /**
     * Store a new template with its contents and variables.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $setting = ReportSetting::create(array_merge(
            ['entity_id' => session('selected_entity_id')],
            $validated,
        ));

        $this->service->syncContents($setting, $request->input('contents', []));

        return redirect()
            ->route('panel.setting.report-settings.index')
            ->with('message', __('actions.report_settings.saved'));
    }

    /**
     * Preview HTML do template — exibido no modal da clínica.
     * Aplica a mesma autorização de show() mas retorna HTML em vez de JSON.
     */
    public function preview(ReportSetting $reportSetting): View
    {
        $this->assertCanPreviewTemplate($reportSetting);

        $reportSetting->load([
            'activeContents' => fn ($q) => $q->orderBy('sort_order')->orderBy('label'),
        ]);

        return view('pdf.report_setting_preview_html', compact('reportSetting'));
    }

    /**
     * Show a template (JSON for modal or full view).
     */
    public function show(ReportSetting $reportSetting): JsonResponse
    {
        $this->assertCanPreviewTemplate($reportSetting);

        $reportSetting->load([
            'category',
            'contents' => fn ($q) => $q
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('label'),
        ]);

        return response()->json([
            'id'             => $reportSetting->id,
            'title'          => $reportSetting->title,
            'description'    => $reportSetting->description,
            'paper_size'     => $reportSetting->paper_size?->value ?? (string) $reportSetting->paper_size,
            'category'       => $reportSetting->category?->name,
            'version'        => $reportSetting->version,
            'show_header'    => (bool) $reportSetting->show_header,
            'show_signature' => (bool) $reportSetting->show_signature,
            'show_footer'    => (bool) $reportSetting->show_footer,
            'contents'       => $reportSetting->contents->map(fn ($content) => [
                'id'      => $content->id,
                'label'   => $content->display_label,
                'type'    => $content->type?->value ?? (string) $content->type,
                'content' => $content->content,
            ])->values(),
        ]);
    }

    public function edit(ReportSetting $reportSetting): InertiaResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        $reportSetting->load('contents.variables');
        $categories = ReportCategory::active()->ordered()->get(['id', 'name']);

        return Inertia::render('Panel/Settings/ReportSettings/Form', [
            'breadcrumbs'   => $this->buildBreadcrumbs(__('actions.report_settings.edit')),
            'mode'          => 'edit',
            'reportSetting' => [
                'id'                 => (string) $reportSetting->id,
                'title'              => $reportSetting->title,
                'description'        => $reportSetting->description,
                'report_category_id' => $reportSetting->report_category_id,
                'paper_size'         => $reportSetting->paper_size instanceof BackedEnum
                    ? $reportSetting->paper_size->value : (string) $reportSetting->paper_size,
                'font_family'         => $reportSetting->font_family,
                'font_size'           => $reportSetting->font_size,
                'margin_top'          => (float) $reportSetting->margin_top,
                'margin_right'        => (float) $reportSetting->margin_right,
                'margin_bottom'       => (float) $reportSetting->margin_bottom,
                'margin_left'         => (float) $reportSetting->margin_left,
                'show_header'         => (bool) $reportSetting->show_header,
                'header_show_logo'    => (bool) $reportSetting->header_show_logo,
                'header_show_name'    => (bool) $reportSetting->header_show_name,
                'header_show_address' => (bool) $reportSetting->header_show_address,
                'header_show_phone'   => (bool) $reportSetting->header_show_phone,
                'show_signature'      => (bool) $reportSetting->show_signature,
                'signature_show_name' => (bool) $reportSetting->signature_show_name,
                'signature_show_crm'  => (bool) $reportSetting->signature_show_crm,
                'signature_show_rqe'  => (bool) $reportSetting->signature_show_rqe,
                'show_footer'         => (bool) $reportSetting->show_footer,
                'footer_text'         => $reportSetting->footer_text,
                'footer_show_address' => (bool) $reportSetting->footer_show_address,
                'footer_show_phone'   => (bool) $reportSetting->footer_show_phone,
                'active'              => (bool) $reportSetting->active,
                'contents'            => $reportSetting->contents->map(fn ($c) => [
                    'id'      => $c->id,
                    'type'    => $c->type instanceof BackedEnum ? $c->type->value : (string) $c->type,
                    'label'   => $c->label,
                    'content' => $c->content,
                    'active'  => (bool) $c->active,
                ])->values(),
            ],
            'categories'          => $categories,
            'paper_sizes'         => array_map(fn (string $v) => ['value' => $v, 'label' => $v], PaperSize::values()),
            'documentation_types' => array_map(fn (string $v) => ['value' => $v, 'label' => $v], DocumentationType::values()),
            'urls'                => [
                'update' => route('panel.setting.report-settings.update', $reportSetting),
                'index'  => route('panel.setting.report-settings.index'),
            ],
        ]);
    }

    /**
     * Update a template.
     */
    public function update(Request $request, ReportSetting $reportSetting): RedirectResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        $validated = $this->validateRequest($request);

        $reportSetting->update($validated);

        $this->service->syncContents($reportSetting, $request->input('contents', []));

        return redirect()
            ->route('panel.setting.report-settings.index')
            ->with('message', __('actions.report_settings.updated'));
    }

    /**
     * Soft-delete a template.
     */
    public function destroy(ReportSetting $reportSetting): RedirectResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        $reportSetting->delete();

        return redirect()
            ->route('panel.setting.report-settings.index')
            ->with('message', __('actions.report_settings.deleted'));
    }

    /**
     * Adota (cópia profunda) um template global para a clínica.
     */
    public function adopt(ReportSetting $reportSetting): RedirectResponse
    {
        $entityId = session('selected_entity_id');

        $this->service->adopt($reportSetting, $entityId);

        return redirect()
            ->route('panel.setting.report-settings.index')
            ->with('message', __('actions.report_settings.adopted'));
    }

    /**
     * Reimporta o conteúdo atualizado de um template global.
     */
    public function reimport(ReportSetting $reportSetting): RedirectResponse
    {
        $this->assertOwnsReportSetting($reportSetting);

        $this->service->reimport($reportSetting);

        return redirect()
            ->route('panel.setting.report-settings.index')
            ->with('message', __('actions.report_settings.reimported'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:1000'],
            'report_category_id' => ['nullable', 'uuid', 'exists:report_categories,id'],
            'paper_size'         => ['nullable', 'string', 'in:' . implode(',', PaperSize::values())],
            'font_family'        => ['nullable', 'string', 'max:50'],
            'font_size'          => ['nullable', 'integer', 'min:8', 'max:24'],
            'margin_top'         => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_right'       => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_bottom'      => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_left'        => ['nullable', 'numeric', 'min:0', 'max:10'],
            // Cabeçalho
            'show_header'         => ['boolean'],
            'header_show_logo'    => ['boolean'],
            'header_show_name'    => ['boolean'],
            'header_show_address' => ['boolean'],
            'header_show_phone'   => ['boolean'],
            // Assinatura
            'show_signature'      => ['boolean'],
            'signature_show_name' => ['boolean'],
            'signature_show_crm'  => ['boolean'],
            'signature_show_rqe'  => ['boolean'],
            // Rodapé
            'show_footer'         => ['boolean'],
            'footer_text'         => ['nullable', 'string', 'max:500'],
            'footer_show_address' => ['boolean'],
            'footer_show_phone'   => ['boolean'],
            'active'              => ['boolean'],
            // Templates de conteúdo
            'contents'           => ['nullable', 'array'],
            'contents.*.type'    => ['required', 'string', 'in:' . implode(',', DocumentationType::values())],
            'contents.*.label'   => ['required', 'string', 'max:255'],
            'contents.*.content' => ['required', 'string'],
            'contents.*.active'  => ['boolean'],
        ]);
    }

    private function buildBreadcrumbs(string $pageTitle): array
    {
        return [
            ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
            ['label' => __('actions.sidemenu.settings'), 'url' => '#', 'active' => false],
            ['label' => __('actions.report_settings.title'), 'url' => route('panel.setting.report-settings.index'), 'active' => false],
            ['label' => $pageTitle, 'url' => '#', 'active' => true],
        ];
    }

    /**
     * Achado de segurança (auditoria panel.* IDOR): $reportSetting chega via
     * route model binding, que roda ANTES de tenant.bind — EntityScope fica
     * inerte nesse momento e o binding resolve QUALQUER template, de qualquer
     * entity (inclusive o template GLOBAL, entity_id null). Diferente de
     * assertCanPreviewTemplate() (que permite ver o global antes de adotar),
     * aqui é escrita/exclusão — só o dono (entity_id da própria clínica) pode
     * editar/excluir/reimportar seu próprio template adotado. O global nunca é
     * editável por uma clínica individual (é gerido centralmente).
     */
    private function assertOwnsReportSetting(ReportSetting $reportSetting): void
    {
        abort_unless(
            (string) ($reportSetting->entity_id ?? '') === (string) session('selected_entity_id'),
            404,
        );
    }

    private function assertCanPreviewTemplate(ReportSetting $reportSetting): void
    {
        $selectedEntityId = (string) session('selected_entity_id');
        $ownerEntityId    = (string) ($reportSetting->entity_id ?? '');

        // Modelo da própria clínica
        if ($ownerEntityId !== '' && $ownerEntityId === $selectedEntityId) {
            return;
        }

        // Modelo global publicado/ativo (pré-visualização antes da adoção)
        if ($ownerEntityId === '') {
            abort_if(! $reportSetting->active, 404);
            abort_if($reportSetting->status !== ReportSettingStatus::Published, 404);

            return;
        }

        // Modelo de outra clínica
        abort(404);
    }
}
