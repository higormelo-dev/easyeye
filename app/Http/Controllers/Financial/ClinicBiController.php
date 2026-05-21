<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\EntityGate;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Services\Financial\ClinicBiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

class ClinicBiController extends Controller
{
    public function __construct(
        private readonly ClinicBiService $biService,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

        $summary = $this->biService->summary((string) $entity->id, $from, $to);
        $trend   = $this->biService->monthlyTrend((string) $entity->id);

        return Inertia::render('Panel/Financial/Bi/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'),  'url' => route('panel.dashboard'),                'active' => false],
                ['label' => __('financial.financial'),         'url' => route('panel.financial.billing.index'), 'active' => false],
                ['label' => __('financial.bi.title'),          'url' => '#',                                    'active' => true],
            ],
            'entity'  => ['id' => $entity->id, 'name' => $entity->name],
            'filters' => ['from' => $from, 'to' => $to],
            'summary' => $summary,
            'trend'   => $trend,
            't'       => trans('financial'),
        ]);
    }
}
