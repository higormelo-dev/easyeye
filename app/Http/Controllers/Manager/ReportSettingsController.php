<?php

namespace App\Http\Controllers\Manager;

use App\DataTables\Manager\ManagerReportSettingsDataTable;
use App\Http\Controllers\Controller;
use App\Models\{ReportSetting, ReportSettingContent};
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

/**
 * Gerencia modelos de documento globais (entity_id = null).
 * Modelos globais ficam disponíveis para todas as clínicas como padrão.
 */
class ReportSettingsController extends Controller
{
    public function __construct(private readonly ManagerReportSettingsDataTable $dataTable) {}

    public function index(): mixed
    {
        $total = ReportSetting::whereNull('entity_id')->count();

        $meta = [
            'title'            => __('actions.report_settings.title') . ' — ' . __('actions.global'),
            'total'            => $total,
            'cardsUrl'         => route('panel.manager.report-settings.cards'),
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'),    'url' => route('panel.dashboard'),                            'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => route('panel.manager.report-settings.index'),        'active' => true],
            ],
        ];

        return $this->dataTable->render('system.report_settings.index', compact('meta'));
    }

    public function cards(Request $request): JsonResponse
    {
        $search  = $request->string('search')->trim()->value();
        $perPage = 12;

        $records = ReportSetting::whereNull('entity_id')
            ->when($search, fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', ['%' . mb_strtolower($search, 'UTF-8') . '%']))
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
                'active'         => (bool) $r->active,
                'edit_url'       => route('panel.manager.report-settings.edit', $r),
                'delete_url'     => route('panel.manager.report-settings.destroy', $r),
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
        $meta = $this->buildMeta(__('actions.report_settings.create'));

        return view('system.report_settings.form', compact('meta'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $setting = ReportSetting::create(array_merge($validated, ['entity_id' => null]));

        $this->syncContents($setting, $request->input('contents', []));

        return redirect()
            ->route('panel.manager.report-settings.index')
            ->with('message', __('actions.report_settings.saved'));
    }

    public function edit(ReportSetting $reportSetting): Factory|Application|View
    {
        $reportSetting->load('contents');
        $meta = $this->buildMeta(__('actions.report_settings.edit'));

        return view('system.report_settings.form', compact('meta', 'reportSetting'));
    }

    public function update(Request $request, ReportSetting $reportSetting): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $reportSetting->update($validated);

        $this->syncContents($reportSetting, $request->input('contents', []));

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

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'title'                => ['required', 'string', 'max:255'],
            'paper_size'           => ['nullable', 'string', 'in:A4,A5,Letter,Legal'],
            'font_family'          => ['nullable', 'string', 'max:50'],
            'font_size'            => ['nullable', 'integer', 'min:8', 'max:24'],
            'margin_top'           => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_right'         => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_bottom'        => ['nullable', 'numeric', 'min:0', 'max:10'],
            'margin_left'          => ['nullable', 'numeric', 'min:0', 'max:10'],
            'show_header'          => ['boolean'],
            'header_show_logo'     => ['boolean'],
            'header_show_name'     => ['boolean'],
            'header_show_address'  => ['boolean'],
            'header_show_phone'    => ['boolean'],
            'show_signature'       => ['boolean'],
            'signature_show_name'  => ['boolean'],
            'signature_show_crm'   => ['boolean'],
            'signature_show_rqe'   => ['boolean'],
            'show_footer'          => ['boolean'],
            'footer_text'          => ['nullable', 'string', 'max:500'],
            'footer_show_address'  => ['boolean'],
            'footer_show_phone'    => ['boolean'],
            'active'               => ['boolean'],
            'contents'             => ['nullable', 'array'],
            'contents.*.type'      => ['required', 'string', 'in:prescription,procedure,certificate,referral,report,tonometry'],
            'contents.*.label'     => ['required', 'string', 'max:255'],
            'contents.*.content'   => ['required', 'string'],
            'contents.*.active'    => ['boolean'],
        ]);
    }

    private function syncContents(ReportSetting $setting, array $contents): void
    {
        $keptIds = [];

        foreach ($contents as $row) {
            $content = isset($row['id']) ? ReportSettingContent::find($row['id']) : null;

            if ($content) {
                $content->update([
                    'type'    => $row['type'],
                    'label'   => $row['label'],
                    'content' => $row['content'],
                    'active'  => $row['active'] ?? true,
                ]);
            } else {
                $content = $setting->contents()->create([
                    'type'    => $row['type'],
                    'label'   => $row['label'],
                    'content' => $row['content'],
                    'active'  => $row['active'] ?? true,
                ]);
            }

            $keptIds[] = $content->id;
        }

        $setting->contents()->whereNotIn('id', $keptIds)->delete();
    }

    private function buildMeta(string $pageTitle): array
    {
        return [
            'title'            => $pageTitle,
            'breadcrumb_title' => false,
            'breadcrumbs'      => [
                ['label' => __('actions.sidemenu.dashboard'),    'url' => route('panel.dashboard'),                      'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => route('panel.manager.report-settings.index'),  'active' => false],
                ['label' => $pageTitle,                          'url' => 'javascript:void(0);',                         'active' => true],
            ],
        ];
    }
}
