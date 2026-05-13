<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\Manager\ManagerReportSettingsDataTable;
use App\DTOs\ActionPolicy;
use App\Enums\{DocumentationType, PaperSize, ReportSettingStatus};
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\Response;
use App\Http\Controllers\Controller;
use App\Models\{ReportCategory, ReportSetting};
use App\Services\ReportSettingService;
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

/**
 * Gerencia modelos de documento globais (entity_id = null).
 * Modelos globais ficam disponíveis para todas as clínicas como padrão.
 */
class ReportSettingsController extends Controller
{
    public function __construct(
        private readonly ManagerReportSettingsDataTable $dataTable,
        private readonly ReportSettingService $service,
    ) {
    }

    public function index(): mixed
    {
        $total      = ReportSetting::global()->count();
        $categories = ReportCategory::active()->ordered()->get();

        $meta = [
            'title'            => __('actions.report_settings.title') . ' — ' . __('actions.global'),
            'total'            => $total,
            'cardsUrl'         => route('panel.manager.report-settings.cards'),
            'breadcrumb_title' => false,
            'isManager'        => true,
            'statuses'         => ReportSettingStatus::values(),
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => route('panel.manager.report-settings.index'), 'active' => true],
            ],
        ];

        return $this->dataTable->render('system.report_settings.index', compact('meta', 'categories'));
    }

    public function cards(Request $request): JsonResponse
    {
        $search     = $request->string('search')->trim()->value();
        $status     = $request->string('status')->trim()->value();
        $categoryId = $request->string('category_id')->trim()->value();
        $perPage    = 12;

        $records = ReportSetting::global()
            ->when($search, fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
            ->when($status, fn ($q) => $q->where('status', $status))
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
                'status'         => $r->status?->value,
                'status_label'   => $r->status?->label(),
                'status_badge'   => $r->status?->badgeClass(),
                'version'        => $r->version,
                'category'       => $r->category?->name,
                'adopted_count'  => $r->adoptedCopies()->count(),
                'edit_url'       => route('panel.manager.report-settings.edit', $r),
                'delete_url'     => route('panel.manager.report-settings.destroy', $r),
                'preview_url'    => route('panel.manager.report-settings.preview', $r),
                'publish_url'    => route('panel.manager.report-settings.publish', $r),
                'archive_url'    => route('panel.manager.report-settings.archive', $r),
                ...ActionPolicy::forManager($r)->toArray(),
            ]),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function create(): Factory|Application|View
    {
        $categories = ReportCategory::active()->ordered()->get();
        $meta       = $this->buildMeta(__('actions.report_settings.create'));

        return view('system.report_settings.form', compact('meta', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $setting = ReportSetting::create(array_merge($validated, [
            'entity_id' => null,
            'status'    => ReportSettingStatus::Draft,
        ]));

        $this->service->syncContents($setting, $request->input('contents', []));

        return redirect()
            ->route('panel.manager.report-settings.index')
            ->with('message', __('actions.report_settings.saved'));
    }

    public function edit(ReportSetting $reportSetting): Factory|Application|View
    {
        $reportSetting->load('contents');
        $categories = ReportCategory::active()->ordered()->get();
        $meta       = $this->buildMeta(__('actions.report_settings.edit'));

        return view('system.report_settings.form', compact('meta', 'reportSetting', 'categories'));
    }

    public function update(Request $request, ReportSetting $reportSetting): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $reportSetting->update($validated);

        $this->service->syncContents($reportSetting, $request->input('contents', []));

        return redirect()
            ->route('panel.manager.report-settings.index')
            ->with('message', __('actions.report_settings.updated'));
    }

    public function destroy(ReportSetting $reportSetting): RedirectResponse
    {
        $reportSetting->delete();

        return redirect()
            ->route('panel.manager.report-settings.index')
            ->with('message', __('actions.report_settings.deleted'));
    }

    /**
     * Preview HTML do template — retorna HTML diretamente para o modal.
     * Usar HTML em vez de PDF garante centralização consistente entre tamanhos
     * de papel (A4/A5/etc.), sem depender do viewer nativo de PDF do browser.
     */
    public function preview(ReportSetting $reportSetting): \Illuminate\Contracts\View\View
    {
        $reportSetting->load([
            'activeContents' => fn ($q) => $q->orderBy('sort_order')->orderBy('label'),
        ]);

        return view('pdf.report_setting_preview_html', compact('reportSetting'));
    }

    /**
     * Publica um template global (draft→published).
     */
    public function publish(ReportSetting $reportSetting): RedirectResponse
    {
        $this->service->publish($reportSetting);

        return redirect()
            ->route('panel.manager.report-settings.index')
            ->with('message', __('actions.report_settings.published'));
    }

    /**
     * Arquiva um template global (published→archived).
     */
    public function archive(ReportSetting $reportSetting): RedirectResponse
    {
        $this->service->archive($reportSetting);

        return redirect()
            ->route('panel.manager.report-settings.index')
            ->with('message', __('actions.report_settings.archived'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'title'               => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string', 'max:1000'],
            'report_category_id'  => ['nullable', 'uuid', 'exists:report_categories,id'],
            'paper_size'          => ['nullable', 'string', 'in:' . implode(',', PaperSize::values())],
            'font_family'         => ['nullable', 'string', 'max:50'],
            'font_size'           => ['nullable', 'integer', 'min:8', 'max:24'],
            'margin_top'          => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_right'        => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_bottom'       => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_left'         => ['nullable', 'numeric', 'min:0', 'max:10'],
            'show_header'         => ['boolean'],
            'header_show_logo'    => ['boolean'],
            'header_show_name'    => ['boolean'],
            'header_show_address' => ['boolean'],
            'header_show_phone'   => ['boolean'],
            'show_signature'      => ['boolean'],
            'signature_show_name' => ['boolean'],
            'signature_show_crm'  => ['boolean'],
            'signature_show_rqe'  => ['boolean'],
            'show_footer'         => ['boolean'],
            'footer_text'         => ['nullable', 'string', 'max:500'],
            'footer_show_address' => ['boolean'],
            'footer_show_phone'   => ['boolean'],
            'active'              => ['boolean'],
            'contents'            => ['nullable', 'array'],
            'contents.*.type'     => ['required', 'string', 'in:' . implode(',', DocumentationType::values())],
            'contents.*.label'    => ['required', 'string', 'max:255'],
            'contents.*.content'  => ['required', 'string'],
            'contents.*.active'   => ['boolean'],
        ]);
    }

    private function buildMeta(string $pageTitle): array
    {
        return [
            'title'            => $pageTitle,
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => route('panel.manager.report-settings.index'), 'active' => false],
                ['label' => $pageTitle, 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];
    }
}
