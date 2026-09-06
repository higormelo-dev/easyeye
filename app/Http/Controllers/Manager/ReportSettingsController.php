<?php

namespace App\Http\Controllers\Manager;

use App\DTOs\ActionPolicy;
use App\Enums\{DocumentationType, PaperSize, ReportSettingStatus};
use App\Http\Controllers\Controller;
use App\Models\{ReportCategory, ReportSetting};
use App\Services\Audit\AuditLogger;
use App\Services\ReportSettingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\{JsonResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Gerencia modelos de documento globais (entity_id = null).
 */
class ReportSettingsController extends Controller
{
    public function __construct(
        private readonly ReportSettingService $service,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $search     = $request->string('search')->trim()->value();
        $status     = $request->string('status')->trim()->value();
        $categoryId = $request->string('category_id')->trim()->value();
        $sort       = in_array($request->string('sort')->value(), ['title', 'status', 'version', 'category']) ? $request->string('sort')->value() : 'title';
        $direction  = $request->string('direction')->value() === 'desc' ? 'desc' : 'asc';

        $reportSettings = ReportSetting::global()
            ->when($search, fn ($q) => $q->whereLikeUnaccent('title', $search))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('report_category_id', $categoryId))
            ->with('category')
            ->withCount('adoptedCopies')
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->through(fn ($r) => $this->toTableRow($r));

        $total      = ReportSetting::global()->count();
        $categories = ReportCategory::active()->ordered()->get(['id', 'name']);

        return Inertia::render('Panel/Manager/ReportSettings/Index', [
            'reportSettings' => $reportSettings,
            'total'          => $total,
            'categories'     => $categories,
            'statuses'       => collect(ReportSettingStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
            ]),
            'paperSizes'   => PaperSize::values(),
            'fontFamilies' => ['Arial', 'Times New Roman', 'Helvetica', 'Courier New', 'Georgia'],
            'filters'      => [
                'search'      => $search,
                'status'      => $status,
                'category_id' => $categoryId,
                'sort'        => $sort,
                'direction'   => $direction,
            ],
            'cardsUrl' => route('manager.report-settings.cards'),
            't'        => trans('manager_report_settings'),
        ]);
    }

    public function cards(Request $request): JsonResponse
    {
        $search     = $request->string('search')->trim()->value();
        $status     = $request->string('status')->trim()->value();
        $categoryId = $request->string('category_id')->trim()->value();
        $perPage    = 12;

        $records = ReportSetting::global()
            ->when($search, fn ($q) => $q->whereLikeUnaccent('title', $search))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($categoryId, fn ($q) => $q->where('report_category_id', $categoryId))
            ->with('category')
            ->withCount('adoptedCopies')
            ->orderBy('title')
            ->paginate($perPage);

        return response()->json([
            'data' => $records->map(fn ($r) => $this->toCardRow($r)),
            'meta' => [
                'total'        => $records->total(),
                'per_page'     => $records->perPage(),
                'current_page' => $records->currentPage(),
                'last_page'    => $records->lastPage(),
            ],
        ]);
    }

    public function show(ReportSetting $reportSetting): JsonResponse
    {
        // BUGFIX (revisao de seguranca): este controller manager só deve enxergar templates globais; sem esse check, {report_setting} resolve para qualquer clínica.
        abort_unless($reportSetting->isGlobal(), 404);

        return response()->json(['data' => $this->toFormData($reportSetting)]);
    }

    public function store(Request $request): mixed
    {
        $validated = $this->validateRequest($request);

        $setting = ReportSetting::create(array_merge($validated, [
            'entity_id' => null,
            'status'    => ReportSettingStatus::Draft,
        ]));

        $this->service->syncContents($setting, $request->input('contents', []));

        if ($request->wantsJson()) {
            return response()->json(['message' => __('actions.report_settings.saved')]);
        }

        return redirect()
            ->route('manager.report-settings.index')
            ->with('message', __('actions.report_settings.saved'));
    }

    public function update(Request $request, ReportSetting $reportSetting): mixed
    {
        // BUGFIX (revisao de seguranca): este controller manager só deve enxergar templates globais; sem esse check, {report_setting} resolve para qualquer clínica.
        abort_unless($reportSetting->isGlobal(), 404);

        $validated = $this->validateRequest($request);

        $reportSetting->update($validated);

        $this->service->syncContents($reportSetting, $request->input('contents', []));

        if ($request->wantsJson()) {
            return response()->json(['message' => __('actions.report_settings.updated')]);
        }

        return redirect()
            ->route('manager.report-settings.index')
            ->with('message', __('actions.report_settings.updated'));
    }

    public function destroy(Request $request, ReportSetting $reportSetting): mixed
    {
        // BUGFIX (revisao de seguranca): este controller manager só deve enxergar templates globais; sem esse check, {report_setting} resolve para qualquer clínica.
        abort_unless($reportSetting->isGlobal(), 404);

        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $reportSetting->delete();

        $this->audit->recordAdminAction(
            event: 'manager.report_setting.destroy',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'report_setting',
            auditableId: (string) $reportSetting->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['title' => $reportSetting->title, 'version' => $reportSetting->version],
            request: $request,
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => __('actions.report_settings.deleted')]);
        }

        return redirect()
            ->route('manager.report-settings.index')
            ->with('message', __('actions.report_settings.deleted'));
    }

    public function preview(ReportSetting $reportSetting): View
    {
        // BUGFIX (revisao de seguranca): este controller manager só deve enxergar templates globais; sem esse check, {report_setting} resolve para qualquer clínica.
        abort_unless($reportSetting->isGlobal(), 404);

        $reportSetting->load([
            'activeContents' => fn ($q) => $q->orderBy('sort_order')->orderBy('label'),
        ]);

        return view('pdf.report_setting_preview_html', compact('reportSetting'));
    }

    public function publish(Request $request, ReportSetting $reportSetting): mixed
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $oldStatus = $reportSetting->status instanceof ReportSettingStatus
            ? $reportSetting->status->value
            : (string) $reportSetting->status;

        $this->service->publish($reportSetting);

        $this->audit->recordAdminAction(
            event: 'manager.report_setting.publish',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'report_setting',
            auditableId: (string) $reportSetting->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['title' => $reportSetting->title, 'version' => $reportSetting->version],
            request: $request,
            oldValues: ['status' => $oldStatus],
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => __('actions.report_settings.published')]);
        }

        return redirect()
            ->route('manager.report-settings.index')
            ->with('message', __('actions.report_settings.published'));
    }

    public function archive(Request $request, ReportSetting $reportSetting): mixed
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ], [
            'reason.required' => __('manager_hardening.reason_required'),
            'reason.min'      => __('manager_hardening.reason_min', ['min' => 20]),
            'reason.max'      => __('manager_hardening.reason_max', ['max' => 1000]),
        ]);

        $oldStatus = $reportSetting->status instanceof ReportSettingStatus
            ? $reportSetting->status->value
            : (string) $reportSetting->status;

        $this->service->archive($reportSetting);

        $this->audit->recordAdminAction(
            event: 'manager.report_setting.archive',
            targetEntityId: null,
            targetUserId: null,
            auditableType: 'report_setting',
            auditableId: (string) $reportSetting->id,
            reason: trim((string) $request->input('reason')),
            newValues: ['title' => $reportSetting->title, 'version' => $reportSetting->version],
            request: $request,
            oldValues: ['status' => $oldStatus],
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => __('actions.report_settings.archived')]);
        }

        return redirect()
            ->route('manager.report-settings.index')
            ->with('message', __('actions.report_settings.archived'));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private function toTableRow(ReportSetting $r): array
    {
        return [
            'id'             => $r->id,
            'title'          => $r->title,
            'paper_size'     => $r->paper_size instanceof PaperSize ? $r->paper_size->value : $r->paper_size,
            'show_header'    => $r->show_header,
            'show_signature' => $r->show_signature,
            'show_footer'    => $r->show_footer,
            'status'         => $r->status?->value,
            'status_label'   => $r->status?->label(),
            'status_badge'   => $r->status?->badgeClass(),
            'version'        => $r->version,
            'category'       => $r->category?->name,
            'adopted_count'  => $r->adopted_copies_count ?? 0,
            'preview_url'    => route('manager.report-settings.preview', $r),
            'publish_url'    => route('manager.report-settings.publish', $r),
            'archive_url'    => route('manager.report-settings.archive', $r),
            ...ActionPolicy::forManager($r)->toArray(),
        ];
    }

    private function toCardRow(ReportSetting $r): array
    {
        return [
            'id'             => $r->id,
            'title'          => $r->title,
            'paper_size'     => $r->paper_size instanceof PaperSize ? $r->paper_size->value : $r->paper_size,
            'show_header'    => $r->show_header,
            'show_signature' => $r->show_signature,
            'show_footer'    => $r->show_footer,
            'status'         => $r->status?->value,
            'status_label'   => $r->status?->label(),
            'status_badge'   => $r->status?->badgeClass(),
            'version'        => $r->version,
            'category'       => $r->category?->name,
            'adopted_count'  => $r->adopted_copies_count ?? 0,
            'preview_url'    => route('manager.report-settings.preview', $r),
            'publish_url'    => route('manager.report-settings.publish', $r),
            'archive_url'    => route('manager.report-settings.archive', $r),
            ...ActionPolicy::forManager($r)->toArray(),
        ];
    }

    private function toFormData(ReportSetting $r): array
    {
        return [
            'id'                  => $r->id,
            'title'               => $r->title ?? '',
            'description'         => $r->description ?? '',
            'report_category_id'  => $r->report_category_id ?? '',
            'paper_size'          => $r->paper_size instanceof PaperSize ? $r->paper_size->value : ($r->paper_size ?? 'A4'),
            'font_family'         => $r->font_family ?? 'Arial',
            'font_size'           => $r->font_size ?? 11,
            'margin_top'          => $r->margin_top ?? 2.0,
            'margin_right'        => $r->margin_right ?? 2.0,
            'margin_bottom'       => $r->margin_bottom ?? 2.0,
            'margin_left'         => $r->margin_left ?? 2.0,
            'show_header'         => (bool) ($r->show_header ?? true),
            'header_show_logo'    => (bool) ($r->header_show_logo ?? true),
            'header_show_name'    => (bool) ($r->header_show_name ?? true),
            'header_show_address' => (bool) ($r->header_show_address ?? false),
            'header_show_phone'   => (bool) ($r->header_show_phone ?? false),
            'show_signature'      => (bool) ($r->show_signature ?? true),
            'signature_show_name' => (bool) ($r->signature_show_name ?? true),
            'signature_show_crm'  => (bool) ($r->signature_show_crm ?? true),
            'signature_show_rqe'  => (bool) ($r->signature_show_rqe ?? true),
            'show_footer'         => (bool) ($r->show_footer ?? false),
            'footer_text'         => $r->footer_text ?? '',
            'footer_show_address' => (bool) ($r->footer_show_address ?? false),
            'footer_show_phone'   => (bool) ($r->footer_show_phone ?? false),
            'active'              => (bool) ($r->active ?? true),
        ];
    }

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
}
