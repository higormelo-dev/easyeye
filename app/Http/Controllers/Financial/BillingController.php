<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Enums\{BillingClaimStatus, EntityGate, ScheduleSituation};
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\{BillingBatchRequest, BillingIndividualRequest};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, Schedule};
use App\Services\Financial\BillingService;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{DB, Gate, Storage};

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {
        $this->titleController = 'Faturamento TISS';
    }

    public function index(Request $request)
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;

        $from = (string) $request->input('from', now()->startOfMonth()->toDateString());
        $to   = (string) $request->input('to', now()->toDateString());

        $eligibleSchedulesQuery = Schedule::query()
            ->with(['patient.person', 'doctor', 'covenant', 'visitType'])
            ->where('entity_id', $entityId)
            ->where('situation', ScheduleSituation::Attended->value)
            ->whereBetween(DB::raw('DATE(date_time)'), [$from, $to])
            ->whereNull('deleted_at');

        if ($request->filled('covenant_id')) {
            $eligibleSchedulesQuery->where('covenant_id', $request->input('covenant_id'));
        }

        $alreadyBilledScheduleIds = BillingClaim::query()
            ->where('entity_id', $entityId)
            ->whereNotIn('status', [BillingClaimStatus::Cancelled->value, BillingClaimStatus::Denied->value])
            ->whereNull('deleted_at')
            ->pluck('schedule_id')
            ->filter()
            ->all();

        $eligibleSchedules = $eligibleSchedulesQuery
            ->when(! empty($alreadyBilledScheduleIds), fn ($q) => $q->whereNotIn('id', $alreadyBilledScheduleIds))
            ->orderBy('date_time')
            ->limit(200)
            ->get();

        $claims = BillingClaim::query()
            ->with(['batch', 'patient.person', 'doctor', 'covenant', 'schedule'])
            ->where('entity_id', $entityId)
            ->when($request->filled('claim_status'), fn ($q) => $q->where('status', $request->input('claim_status')))
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $batches = BillingBatch::query()
            ->with(['covenant'])
            ->withCount('claims')
            ->where('entity_id', $entityId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(60)
            ->get();

        $covenants = Covenant::query()
            ->where(function ($q) use ($entityId): void {
                $q->where('entity_id', $entityId)->orWhereNull('entity_id');
            })
            ->where('active', true)
            ->where('table', true)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        if ($covenants->isEmpty()) {
            $covenants = Covenant::query()
                ->where(function ($q) use ($entityId): void {
                    $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                })
                ->where('active', true)
                ->whereNull('deleted_at')
                ->orderBy('name')
                ->get();
        }

        $tissVersionOptions  = ['202603', '202601'];
        $tissLayoutOptions   = ['04.03.00', '01.06.00'];
        $selectedTissVersion = (string) $request->input('tiss_version', '202603');
        $selectedTissLayout  = (string) $request->input('tiss_layout_version', '04.03.00');

        $meta = [
            'title'       => __('financial.billing.title'),
            'action'      => __('financial.financial'),
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.billing.index'), 'active' => false],
                ['label' => __('financial.billing.breadcrumb'), 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        return view('system.financial.billing.index', compact(
            'meta',
            'eligibleSchedules',
            'claims',
            'batches',
            'covenants',
            'from',
            'to',
            'tissVersionOptions',
            'tissLayoutOptions',
            'selectedTissVersion',
            'selectedTissLayout',
        ));
    }

    public function storeIndividual(BillingIndividualRequest $request): RedirectResponse
    {
        $this->authorizeFinancial();

        $this->billingService->createIndividual($request->validated());

        return back()->with('message', __('financial.billing.individual_created'));
    }

    public function storeBatch(BillingBatchRequest $request): RedirectResponse
    {
        $this->authorizeFinancial();

        $batch = $this->billingService->createBatch($request->validated());

        return back()->with('message', __('financial.billing.batch_created', ['code' => $batch->code]));
    }

    public function submitBatch(BillingBatch $batch): RedirectResponse
    {
        $this->authorizeFinancial();

        $batch = $this->billingService->submitBatch($batch);

        return back()->with('message', __('financial.billing.batch_submitted', ['code' => $batch->code]));
    }

    public function exportBatchXml(BillingBatch $batch)
    {
        $this->authorizeFinancial();

        if (blank($batch->xml_path) || ! Storage::disk('local')->exists($batch->xml_path)) {
            $batch = $this->billingService->generateBatchXml($batch);
        }

        return Storage::disk('local')->download(
            $batch->xml_path,
            mb_strtolower($batch->code) . '.xml',
            ['Content-Type' => 'application/xml'],
        );
    }

    public function markClaimPaid(Request $request, BillingClaim $claim): RedirectResponse
    {
        $this->authorizeFinancial();

        $validated = $request->validate([
            'paid_amount'    => ['nullable', 'numeric', 'min:0'],
            'paid_at'        => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:40'],
            'notes'          => ['nullable', 'string', 'max:1000'],
        ]);

        $this->billingService->markClaimPaid($claim, $validated);

        return back()->with('message', __('financial.billing.claim_paid', ['code' => $claim->code]));
    }

    public function markClaimDenied(Request $request, BillingClaim $claim): RedirectResponse
    {
        $this->authorizeFinancial();

        $validated = $request->validate([
            'glosa_amount' => ['nullable', 'numeric', 'min:0'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ]);

        $this->billingService->markClaimDenied($claim, $validated);

        return back()->with('message', __('financial.billing.claim_denied', ['code' => $claim->code]));
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
