<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Financial\ClinicBiService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClinicBiController extends Controller
{
    public function __construct(
        private readonly ClinicBiService $biService,
    ) {
    }

    public function index(Request $request): View
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

        $summary = $this->biService->summary((string) $entity->id, $from, $to);
        $trend   = $this->biService->monthlyTrend((string) $entity->id);

        $meta = [
            'title'       => __('financial.bi.title'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.billing.index'), 'active' => false],
                ['label' => __('financial.bi.title'), 'url' => 'javascript:void(0);', 'active' => true],
            ],
        ];

        return view('system.financial.bi.index', compact(
            'meta',
            'entity',
            'from',
            'to',
            'summary',
            'trend',
        ));
    }
}
