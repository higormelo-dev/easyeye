<?php

namespace App\Http\Controllers\Setting;

use App\Http\Controllers\Controller;
use App\Models\{ReportSetting, ReportSettingContent};
use Illuminate\Contracts\View\{Factory, View};
use Illuminate\Foundation\Application;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};

class ReportSettingsController extends Controller
{
    /**
     * List all templates for the selected entity.
     */
    public function index(): Factory|Application|View
    {
        $entityId = session('selected_entity_id');

        $settings = ReportSetting::with('contents')
            ->where('entity_id', $entityId)
            ->orderBy('title')
            ->paginate(15);

        $meta = [
            'title'       => __('actions.report_settings.title'),
            'action'      => __('actions.report_settings.title'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'),          'active' => false],
                ['label' => __('actions.sidemenu.settings'),  'url' => 'javascript:void(0);',             'active' => false],
                ['label' => __('actions.report_settings.title'), 'url' => 'javascript:void(0);',          'active' => true],
            ],
        ];

        return view('system.report_settings.index', compact('meta', 'settings'));
    }

    /**
     * Show the form to create a new template.
     */
    public function create(): Factory|Application|View
    {
        $meta = $this->buildMeta(__('actions.report_settings.create'));

        return view('system.report_settings.form', compact('meta'));
    }

    /**
     * Store a new template with its contents and variables.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $setting = ReportSetting::create([
            'entity_id'       => session('selected_entity_id'),
            'title'           => $validated['title'],
            'paper_size'      => $validated['paper_size'] ?? 'A4',
            'font_family'     => $validated['font_family'] ?? 'Arial',
            'font_size'       => $validated['font_size'] ?? 12,
            'margin_top'      => $validated['margin_top'] ?? 20,
            'margin_right'    => $validated['margin_right'] ?? 15,
            'margin_bottom'   => $validated['margin_bottom'] ?? 20,
            'margin_left'     => $validated['margin_left'] ?? 15,
            'patient_name'    => $validated['patient_name'] ?? true,
            'patient_birth'   => $validated['patient_birth'] ?? true,
            'patient_address' => $validated['patient_address'] ?? false,
            'show_signature'  => $validated['show_signature'] ?? true,
            'signature_name'  => $validated['signature_name'] ?? null,
            'signature_role'  => $validated['signature_role'] ?? null,
            'show_logo'       => $validated['show_logo'] ?? true,
            'show_footer'     => $validated['show_footer'] ?? true,
            'footer_text'     => $validated['footer_text'] ?? null,
            'active'          => $validated['active'] ?? true,
        ]);

        $this->syncContents($setting, $request->input('contents', []));

        return redirect()
            ->route('panel.setting.report-settings.index')
            ->with('message', __('actions.report_settings.saved'));
    }

    /**
     * Show a template (JSON for modal or full view).
     */
    public function show(ReportSetting $reportSetting): JsonResponse
    {
        $reportSetting->load('contents.variables');

        return response()->json($reportSetting);
    }

    /**
     * Show form for editing a template.
     */
    public function edit(ReportSetting $reportSetting): Factory|Application|View
    {
        $reportSetting->load('contents.variables');
        $meta = $this->buildMeta(__('actions.report_settings.edit'));

        return view('system.report_settings.form', compact('meta', 'reportSetting'));
    }

    /**
     * Update a template.
     */
    public function update(Request $request, ReportSetting $reportSetting): RedirectResponse
    {
        $validated = $this->validateRequest($request);

        $reportSetting->update($validated);

        $this->syncContents($reportSetting, $request->input('contents', []));

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

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'title'              => ['required', 'string', 'max:255'],
            'paper_size'         => ['nullable', 'string', 'in:A4,Letter,Legal'],
            'font_family'        => ['nullable', 'string', 'max:50'],
            'font_size'          => ['nullable', 'integer', 'min:8', 'max:24'],
            'margin_top'         => ['nullable', 'integer', 'min:0', 'max:100'],
            'margin_right'       => ['nullable', 'integer', 'min:0', 'max:100'],
            'margin_bottom'      => ['nullable', 'integer', 'min:0', 'max:100'],
            'margin_left'        => ['nullable', 'integer', 'min:0', 'max:100'],
            'patient_name'       => ['boolean'],
            'patient_birth'      => ['boolean'],
            'patient_address'    => ['boolean'],
            'show_signature'     => ['boolean'],
            'signature_name'     => ['nullable', 'string', 'max:255'],
            'signature_role'     => ['nullable', 'string', 'max:100'],
            'show_logo'          => ['boolean'],
            'show_footer'        => ['boolean'],
            'footer_text'        => ['nullable', 'string', 'max:500'],
            'active'             => ['boolean'],
            'contents'           => ['nullable', 'array'],
            'contents.*.type'    => ['required', 'string', 'in:prescription,procedure,certificate,referral,report'],
            'contents.*.label'   => ['required', 'string', 'max:255'],
            'contents.*.content' => ['required', 'string'],
            'contents.*.active'  => ['boolean'],
        ]);
    }

    private function syncContents(ReportSetting $setting, array $contents): void
    {
        // Keep IDs present in request; delete omitted ones
        $keptIds = [];

        foreach ($contents as $row) {
            $content = isset($row['id'])
                ? ReportSettingContent::find($row['id'])
                : null;

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
            'title'       => $pageTitle,
            'action'      => $pageTitle,
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'),      'url' => route('panel.dashboard'),                        'active' => false],
                ['label' => __('actions.sidemenu.settings'),       'url' => 'javascript:void(0);',                           'active' => false],
                ['label' => __('actions.report_settings.title'),   'url' => route('panel.setting.report-settings.index'),    'active' => false],
                ['label' => $pageTitle,                            'url' => 'javascript:void(0);',                           'active' => true],
            ],
        ];
    }
}
