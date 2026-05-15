<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\Actions\OpenGlosaAppealAction;
use App\Domains\Tiss\Enums\TissGlosaStatus;
use App\Domains\Tiss\Models\TissGlosa;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\{RedirectResponse, Request};

class TissGlosasController extends Controller
{
    public function __construct(
        private readonly OpenGlosaAppealAction $openAppeal,
    ) {
    }

    public function index(Request $request): View
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

        $meta = [
            'title'       => 'Conciliação de Glosas',
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => 'Financeiro', 'url' => route('panel.financial.billing.index'), 'active' => false],
                ['label' => 'Conciliação de Glosas', 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        return view('system.financial.tiss.glosas.index', compact(
            'meta',
            'glosas',
            'from',
            'to',
            'totalAmount',
            'openAmount',
            'appealedAmount',
            'recoveredAmount',
            'byOperator',
        ));
    }

    public function appeal(Request $request, TissGlosa $glosa): RedirectResponse
    {
        abort_if((string) $glosa->entity_id !== session('selected_entity_id'), 403);
        abort_if(! $glosa->status->isActionable(), 409, 'Esta glosa não pode ser recorrida no estado atual.');

        $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ], [
            'reason.required' => 'Informe o motivo do recurso.',
        ]);

        $appeal = ($this->openAppeal)($glosa, [
            'reason'           => $request->input('reason'),
            'requested_amount' => $glosa->amount,
        ]);

        return redirect()
            ->route('panel.financial.tiss.glosas.index')
            ->with('message', "Recurso {$appeal->appeal_number} aberto com sucesso.");
    }
}
