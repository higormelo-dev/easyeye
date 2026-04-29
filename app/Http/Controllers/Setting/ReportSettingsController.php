<?php

namespace App\Http\Controllers\Setting;

use App\DataTables\ReportSettingsDataTable;
use App\DTOs\ActionPolicy;
use App\Enums\{DocumentationType, PaperSize, ReportSettingStatus};
use App\Http\Controllers\Controller;
use App\Models\{ReportCategory, ReportSetting};
use App\Services\ReportSettingService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

class ReportSettingsController extends Controller
{
    public function __construct(
        private readonly ReportSettingsDataTable $dataTable,
        private readonly ReportSettingService $service,
    ) {
    }

    /**
     * List all templates for the selected entity.
     */
    public function index(): mixed
    {
        $entityId = session('selected_entity_id');
        $this->service->adoptPublishedGlobalsForEntity((string) $entityId);
        $total      = ReportSetting::forEntity($entityId)->count();
        $categories = ReportCategory::active()->ordered()->get();

        $globalTemplates = $this->service->getPublishedGlobalTemplates();

        $meta = [
            'title'            => __('actions.report_settings.title'),
            'total'            => $total,
            'cardsUrl'         => route('panel.setting.report-settings.cards'),
            'breadcrumb_title' => false,
            'isManager'        => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.settings'), 'url' => 'javascript:void(0);', 'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => route('panel.setting.report-settings.index'), 'active' => true],
            ],
        ];

        return $this->dataTable->render('system.report_settings.index', compact('meta', 'categories', 'globalTemplates'));
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
            ->when($search, fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
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

    /**
     * Show the form to create a new template.
     */
    public function create(): Factory|Application|View
    {
        $categories = ReportCategory::active()->ordered()->get();
        $meta       = $this->buildMeta(__('actions.report_settings.create'));

        return view('system.report_settings.form', compact('meta', 'categories'));
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

    /**
     * Show form for editing a template.
     */
    public function edit(ReportSetting $reportSetting): Factory|Application|View
    {
        $reportSetting->load('contents.variables');
        $categories = ReportCategory::active()->ordered()->get();
        $meta       = $this->buildMeta(__('actions.report_settings.edit'));

        return view('system.report_settings.form', compact('meta', 'reportSetting', 'categories'));
    }

    /**
     * Update a template.
     */
    public function update(Request $request, ReportSetting $reportSetting): RedirectResponse
    {
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

    private function buildMeta(string $pageTitle): array
    {
        return [
            'title'       => $pageTitle,
            'action'      => $pageTitle,
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.settings'), 'url' => 'javascript:void(0);', 'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => route('panel.setting.report-settings.index'), 'active' => false],
                ['label' => $pageTitle, 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];
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
