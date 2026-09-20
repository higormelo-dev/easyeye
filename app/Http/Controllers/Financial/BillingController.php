<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial;

use App\Domains\Tiss\Models\{TissGlosaReason, TissTussCode};
use App\Domains\Tiss\Services\TissWorkflowService;
use App\Enums\{BillingBatchStatus, BillingClaimStatus, EntityGate, ScheduleSituation};
use App\Http\Controllers\Controller;
use App\Http\Requests\Financial\{BillingBatchRequest, BillingIndividualRequest};
use App\Models\{BillingBatch, BillingClaim, Covenant, Entity, Schedule};
use App\Services\Financial\BillingService;
use BackedEnum;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\{DB, Gate, Storage};
use Inertia\{Inertia, Response as InertiaResponse};
use Throwable;

class BillingController extends Controller
{
    public function __construct(
        private readonly BillingService $billingService,
    ) {
        $this->titleController = 'Faturamento TISS';
    }

    public function index(Request $request): InertiaResponse
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
            ->with(['batch', 'patient.person', 'doctor', 'covenant', 'schedule', 'tissGuide'])
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

        return Inertia::render('Panel/Financial/Billing/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial.financial'), 'url' => route('panel.financial.billing.index'), 'active' => false],
                ['label' => __('financial.billing.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'eligibleSchedules' => $eligibleSchedules->map(fn ($s) => [
                'id'            => $s->id,
                'date_time'     => $s->date_time?->format('d/m/Y H:i'),
                'patient_name'  => $s->patient?->person?->name,
                'doctor_name'   => $s->doctor?->name,
                'covenant_name' => $s->covenant?->name,
                'covenant_id'   => $s->covenant_id,
                'visit_type'    => $s->visitType?->name,
            ]),
            'claims' => $claims->map(fn ($c) => [
                'id'                => $c->id,
                'created_at'        => $c->created_at?->format('d/m/Y H:i'),
                'patient_name'      => $c->patient?->person?->name,
                'doctor_name'       => $c->doctor?->name,
                'covenant_name'     => $c->covenant?->name,
                'batch_id'          => $c->batch_id,
                'status'            => $c->status instanceof BackedEnum ? $c->status->value : $c->status,
                'status_label'      => $c->status instanceof BillingClaimStatus ? $c->status->label() : (string) $c->status,
                'status_badge'      => $c->status instanceof BillingClaimStatus ? $c->status->badgeClass() : 'bg-secondary',
                'amount'            => (float) ($c->amount ?? 0),
                'guide_number'      => $c->tissGuide?->guide_number_provider,
                'has_pending_guide' => (bool) $c->tissGuide && filled($c->tissGuide->errors),
                'pre_validate_url'  => $c->tiss_guide_id
                    ? route('panel.financial.tiss.guides.pre-validate', $c->tiss_guide_id)
                    : null,
                'mark_paid_url'   => route('panel.financial.billing.claims.paid', $c->id),
                'mark_denied_url' => route('panel.financial.billing.claims.denied', $c->id),
            ]),
            'batches' => $batches->map(fn ($b) => [
                'id'            => $b->id,
                'created_at'    => $b->created_at?->format('d/m/Y H:i'),
                'covenant_name' => $b->covenant?->name,
                'claims_count'  => (int) $b->claims_count,
                'status'        => $b->status instanceof BackedEnum ? $b->status->value : $b->status,
                'status_label'  => $b->status instanceof BillingBatchStatus ? $b->status->label() : (string) $b->status,
                'status_badge'  => $b->status instanceof BillingBatchStatus ? $b->status->badgeClass() : 'bg-secondary',
                'is_tiss'       => (bool) $b->tiss_batch_id,
                'notes'         => $b->notes,
                'submit_url'    => route('panel.financial.billing.batches.submit', $b->id),
                'xml_url'       => route('panel.financial.billing.batches.xml', $b->id),
            ]),
            'covenants' => $covenants->map(fn ($c) => [
                'id'                => $c->id,
                'name'              => $c->name,
                'has_ans_registry'  => filled($c->ans_registry),
                'has_tiss_operator' => filled($c->tiss_operator_id),
                'tiss_operator_id'  => $c->tiss_operator_id,
            ]),
            'filters'             => ['from' => $from, 'to' => $to],
            'tissVersionOptions'  => $tissVersionOptions,
            'tissLayoutOptions'   => $tissLayoutOptions,
            'selectedTissVersion' => $selectedTissVersion,
            'selectedTissLayout'  => $selectedTissLayout,
            'storeIndividualUrl'  => route('panel.financial.billing.individual.store'),
            'storeBatchUrl'       => route('panel.financial.billing.batch.store'),
            'importReturnUrl'     => route('panel.financial.billing.import-return'),
            'glosaReasons'        => TissGlosaReason::query()
                ->where('active', true)
                ->orderBy('code')
                ->get(['code', 'description'])
                ->map(fn (TissGlosaReason $r) => [
                    'code'        => $r->code,
                    'description' => $r->description,
                    'label'       => "{$r->code} — {$r->description}",
                ]),
            'tussCodes' => TissTussCode::query()
                ->where('active', true)
                ->orderBy('description')
                ->get(['code', 'description'])
                ->map(fn (TissTussCode $c) => [
                    'code'        => $c->code,
                    'description' => $c->description,
                    'label'       => "{$c->code} — {$c->description}",
                ]),
            't' => trans('financial'),
        ]);
    }

    public function storeIndividual(BillingIndividualRequest $request): RedirectResponse
    {
        $this->authorizeFinancial();

        $this->billingService->createIndividual($request->validated());

        return back()->with('success', __('financial.billing.individual_created'));
    }

    public function storeBatch(BillingBatchRequest $request): RedirectResponse
    {
        $this->authorizeFinancial();

        $batch = $this->billingService->createBatch($request->validated());

        return back()->with('success', __('financial.billing.batch_created', ['code' => $batch->code]));
    }

    public function submitBatch(BillingBatch $batch): RedirectResponse
    {
        $this->authorizeFinancial();

        $batch = $this->billingService->submitBatch($batch);

        return back()->with('success', __('financial.billing.batch_submitted', ['code' => $batch->code]));
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

        return back()->with('success', __('financial.billing.claim_paid', ['code' => $claim->code]));
    }

    public function markClaimDenied(Request $request, BillingClaim $claim): RedirectResponse
    {
        $this->authorizeFinancial();

        $validated = $request->validate([
            'glosa_amount' => ['nullable', 'numeric', 'min:0'],
            'glosa_code'   => ['nullable', 'string', 'max:30'],
            'notes'        => ['nullable', 'string', 'max:1000'],
        ]);

        $this->billingService->markClaimDenied($claim, $validated);

        return back()->with('success', __('financial.billing.claim_denied', ['code' => $claim->code]));
    }

    public function importReturn(Request $request): RedirectResponse
    {
        $entity = $this->authorizeFinancial();

        $validated = $request->validate([
            'covenant_id' => ['required', 'uuid'],
            'xml_file'    => ['required', 'file', 'extensions:xml', 'max:10240'],
        ]);

        $covenant = Covenant::query()
            ->where('id', $validated['covenant_id'])
            ->where(function ($q) use ($entity): void {
                $q->where('entity_id', (string) $entity->id)->orWhereNull('entity_id');
            })
            ->firstOrFail();

        if (blank($covenant->tiss_operator_id)) {
            return back()->with('error', __('financial.billing.import_return_no_operator'));
        }

        $xmlContent = file_get_contents($validated['xml_file']->getRealPath());

        try {
            $return = app(TissWorkflowService::class)->receiveResponse(
                entityId: (string) $entity->id,
                operatorId: (string) $covenant->tiss_operator_id,
                xmlContent: (string) $xmlContent,
                processSynchronously: true,
            );
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', __('financial.billing.import_return_failed'));
        }

        $glosaCount = (int) ($return->summary['glosa_count'] ?? 0);

        return back()->with(
            'success',
            $glosaCount > 0
                ? __('financial.billing.import_return_success', ['count' => $glosaCount])
                : __('financial.billing.import_return_empty'),
        );
    }

    private function authorizeFinancial(): Entity
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));
        Gate::authorize(EntityGate::ViewFinancial->value, $entity);

        return $entity;
    }
}
