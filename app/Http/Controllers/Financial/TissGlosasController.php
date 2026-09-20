<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\Actions\{OpenGlosaAppealAction, ResolveGlosaAppealAction, SubmitGlosaAppealAction};
use App\Domains\Tiss\Enums\{TissAppealStatus, TissGlosaStatus};
use App\Domains\Tiss\Models\{TissGlosa, TissGlosaAppeal};
use App\Http\Controllers\Controller;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Validation\Rule;
use Inertia\{Inertia, Response as InertiaResponse};

class TissGlosasController extends Controller
{
    public function __construct(
        private readonly OpenGlosaAppealAction $openAppeal,
        private readonly SubmitGlosaAppealAction $submitGlosaAppeal,
        private readonly ResolveGlosaAppealAction $resolveGlosaAppeal,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entityId = session('selected_entity_id');

        $from = $request->date('from', 'Y-m-d') ?? now()->startOfMonth();
        $to   = $request->date('to', 'Y-m-d') ?? now()->endOfMonth();

        $glosas = TissGlosa::query()
            ->forEntity((string) $entityId)
            ->with(['operator', 'guide', 'appeals'])
            ->whereBetween('identified_at', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('identified_at')
            ->get();

        // Summary cards
        $totalAmount     = $glosas->sum('amount');
        $openAmount      = $glosas->where('status', TissGlosaStatus::Open)->sum('amount');
        $appealedAmount  = $glosas->where('status', TissGlosaStatus::Appealed)->sum('amount');
        $recoveredAmount = $glosas
            ->whereIn('status', [TissGlosaStatus::Reversed, TissGlosaStatus::PartialReversed])
            ->sum('amount');

        // "Vencendo em breve" independe do filtro de período (identified_at) — o que
        // importa aqui é o prazo (deadline), não quando a glosa foi identificada.
        $dueSoonDays   = 5;
        $dueSoonUntil  = now()->addDays($dueSoonDays)->toDateString();
        $dueSoonGlosas = TissGlosa::query()
            ->forEntity($entityId)
            ->where('status', TissGlosaStatus::Open->value)
            ->whereNotNull('deadline')
            ->whereBetween('deadline', [now()->toDateString(), $dueSoonUntil])
            ->get();

        // Group by operator for the operator breakdown
        $byOperator = $glosas
            ->groupBy(fn ($g) => $g->operator?->trade_name ?? $g->operator?->name ?? 'Sem convênio')
            ->map(fn ($group, $name) => [
                'name'  => $name,
                'total' => $group->sum('amount'),
                'open'  => $group->where('status', TissGlosaStatus::Open)->sum('amount'),
                'count' => $group->count(),
            ])
            ->sortByDesc('open');

        return Inertia::render('Panel/Financial/Tiss/GlosasIndex', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.billing.index'), 'active' => false],
                ['label' => __('financial.glosas.title'), 'url' => '#', 'active' => true],
            ],
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'total'          => (float) $totalAmount,
                'open'           => (float) $openAmount,
                'appealed'       => (float) $appealedAmount,
                'recovered'      => (float) $recoveredAmount,
                'count'          => $glosas->count(),
                'due_soon'       => (float) $dueSoonGlosas->sum('amount'),
                'due_soon_count' => $dueSoonGlosas->count(),
                'due_soon_days'  => $dueSoonDays,
            ],
            'glosas' => $glosas->map(fn (TissGlosa $g) => [
                'id'            => (string) $g->id,
                'identified_at' => $g->identified_at?->format('d/m/Y'),
                'deadline'      => $g->deadline?->format('d/m/Y'),
                'operator_name' => $g->operator?->trade_name ?? $g->operator?->name,
                'guide_number'  => $g->guide?->guide_number,
                'reason_code'   => $g->glosa_code,
                'reason_text'   => $g->glosa_description,
                'amount'        => (float) $g->amount,
                'status'        => $g->status->value,
                'status_label'  => $g->status->label(),
                'is_actionable' => $g->status->isActionable(),
                'appeals_count' => $g->appeals->count(),
                'appeal_url'    => route('panel.financial.tiss.glosas.appeal', $g->id),
                'appeals'       => $g->appeals->map(fn (TissGlosaAppeal $a) => [
                    'id'               => (string) $a->id,
                    'appeal_number'    => $a->appeal_number,
                    'status'           => $a->status->value,
                    'status_label'     => $a->status->label(),
                    'status_color'     => $a->status->color(),
                    'can_be_submitted' => $a->status->canBeSubmitted(),
                    'can_be_resolved'  => $a->status->canBeResolved(),
                    'requested_amount' => (float) $a->requested_amount,
                    'accepted_amount'  => (float) $a->accepted_amount,
                    'deadline'         => $a->deadline?->format('d/m/Y'),
                    'submit_url'       => route('panel.financial.tiss.glosas.appeals.submit', $a->id),
                    'resolve_url'      => route('panel.financial.tiss.glosas.appeals.resolve', $a->id),
                ])->values(),
            ]),
            'byOperator' => $byOperator->values()->all(),
            't'          => trans('financial'),
        ]);
    }

    public function appeal(Request $request, TissGlosa $glosa): RedirectResponse
    {
        abort_if((string) $glosa->entity_id !== session('selected_entity_id'), 403);
        abort_if(! $glosa->status->isActionable(), 409, __('financial.glosas.cannot_appeal'));

        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => __('financial.glosas.reason_required'),
        ]);

        $appeal = ($this->openAppeal)($glosa, [
            'reason'           => $request->input('reason'),
            'requested_amount' => $glosa->amount,
        ]);

        return redirect()
            ->route('panel.financial.tiss.glosas.index')
            ->with('success', __('financial.glosas.appeal_success', ['number' => $appeal->appeal_number]));
    }

    public function submitAppeal(Request $request, TissGlosaAppeal $appeal): RedirectResponse
    {
        abort_if((string) $appeal->entity_id !== session('selected_entity_id'), 403);
        abort_if(! $appeal->status->canBeSubmitted(), 409, __('financial.glosas.cannot_submit_appeal'));

        ($this->submitGlosaAppeal)($appeal);

        return redirect()
            ->route('panel.financial.tiss.glosas.index')
            ->with('success', __('financial.glosas.appeal_submitted', ['number' => $appeal->appeal_number]));
    }

    public function resolveAppeal(Request $request, TissGlosaAppeal $appeal): RedirectResponse
    {
        abort_if((string) $appeal->entity_id !== session('selected_entity_id'), 403);
        abort_if(! $appeal->status->canBeResolved(), 409, __('financial.glosas.cannot_resolve_appeal'));

        $validated = $request->validate([
            'decision'        => ['required', Rule::in([TissAppealStatus::Accepted->value, TissAppealStatus::Rejected->value])],
            'accepted_amount' => ['nullable', 'numeric', 'min:0'],
            'result_notes'    => ['nullable', 'string', 'max:1000'],
        ]);

        ($this->resolveGlosaAppeal)($appeal, TissAppealStatus::from($validated['decision']), $validated);

        return redirect()
            ->route('panel.financial.tiss.glosas.index')
            ->with('success', __('financial.glosas.appeal_resolved', ['number' => $appeal->appeal_number]));
    }
}
