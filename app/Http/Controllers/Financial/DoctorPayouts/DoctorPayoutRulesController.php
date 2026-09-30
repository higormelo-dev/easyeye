<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Enums\DoctorPayout\{DoctorPayoutCalculation, DoctorPayoutPayerScope, DoctorPayoutServiceType};
use App\Http\Controllers\Concerns\RedirectsToListing;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\AuthorizesDoctorPayouts;
use App\Http\Requests\Financial\DoctorPayoutRuleRequest;
use App\Models\{DoctorPayoutDeductionRate, DoctorPayoutRule};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutOptions, DoctorPayoutRuleService};
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Str;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Financeiro › Repasse médico › Regras. Listagem paginada no servidor com
 * filtros (médico, tipo, situação) — mesmo desenho das listagens recentes
 * (AccessControl\RolesController).
 */
class DoctorPayoutRulesController extends Controller
{
    use AuthorizesDoctorPayouts;
    use RedirectsToListing;

    private const LISTING_PARAMS = ['doctor', 'service_type', 'status', 'page'];

    private const PER_PAGE = 20;

    /** Filtro de médico "regras gerais" (sem médico específico). */
    private const DOCTOR_GENERAL = 'general';

    private const STATUSES = ['active', 'inactive'];

    public function __construct(
        private readonly DoctorPayoutRuleService $rules,
        private readonly DoctorPayoutOptions $options,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;
        $doctors  = $this->options->doctors($entityId);

        $doctor = $this->queryText($request, 'doctor');
        $doctor = $doctor === self::DOCTOR_GENERAL || in_array($doctor, array_column($doctors, 'id'), true) ? $doctor : '';

        $serviceType = $this->queryText($request, 'service_type');
        $serviceType = in_array($serviceType, DoctorPayoutServiceType::values(), true) ? $serviceType : '';

        $status = $this->queryText($request, 'status');
        $status = in_array($status, self::STATUSES, true) ? $status : '';

        $query = DoctorPayoutRule::query()
            ->where('doctor_payout_rules.entity_id', $entityId)
            ->with([...$this->rowRelations()])
            ->when($doctor === self::DOCTOR_GENERAL, fn (Builder $q) => $q->whereNull('doctor_id'))
            ->when($doctor !== '' && $doctor !== self::DOCTOR_GENERAL, fn (Builder $q) => $q->where('doctor_id', $doctor))
            ->when($serviceType !== '', fn (Builder $q) => $q->where('service_type', $serviceType))
            ->when($status !== '', fn (Builder $q) => $q->where('active', $status === 'active'))
            // Regras gerais primeiro, depois por tipo e vigência — a ordem em que
            // a clínica costuma ler a tabela de repasse.
            ->orderByRaw('doctor_id IS NOT NULL')
            ->orderBy('service_type')
            ->orderByRaw('valid_from DESC NULLS LAST')
            ->orderByDesc('created_at')
            ->orderBy('id');

        $rules = $this->paginateClamped($query, self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DoctorPayoutRule $rule) => $this->row($rule));

        return Inertia::render('Panel/Financial/DoctorPayouts/Rules', [
            'breadcrumbs' => $this->payoutBreadcrumbs(__('financial_doctor_payouts.tabs.rules')),
            'rules'       => $rules,
            'filters'     => ['doctor' => $doctor, 'service_type' => $serviceType, 'status' => $status],
            'options'     => [
                'doctors'     => $doctors,
                'visit_types' => $this->options->visitTypes($entityId),
                'procedures'  => $this->options->procedures($entityId),
                'exam_types'  => $this->options->examTypes($entityId),
                'covenants'   => $this->options->covenants($entityId),
            ],
            'settings' => [
                'doctor_payouts_visible' => (bool) $entity->doctor_payouts_visible,
                'can_manage'             => $this->isEntityAdmin($entity),
            ],
            'tabs'   => $this->payoutTabs(),
            'routes' => [
                'index'                  => route('panel.financial.doctor-payouts.rules.index'),
                'store'                  => route('panel.financial.doctor-payouts.rules.store'),
                'update'                 => route('panel.financial.doctor-payouts.rules.update', ['__ID__']),
                'destroy'                => route('panel.financial.doctor-payouts.rules.destroy', ['__ID__']),
                'settings'               => route('panel.financial.doctor-payouts.settings.update'),
                'deduction_rate_store'   => route('panel.financial.doctor-payouts.deduction-rates.store'),
                'deduction_rate_destroy' => route('panel.financial.doctor-payouts.deduction-rates.destroy', ['__ID__']),
            ],
            'deduction_rates' => DoctorPayoutDeductionRate::query()
                ->where('entity_id', $entityId)
                ->orderBy('kind')
                ->orderByDesc('valid_from')
                ->get()
                ->map(fn (DoctorPayoutDeductionRate $rate) => [
                    'id'         => $rate->id,
                    'kind'       => $rate->kind->value,
                    'percentage' => (float) $rate->percentage,
                    'valid_from' => $rate->valid_from->toDateString(),
                    'notes'      => $rate->notes,
                ])
                ->values()
                ->all(),
            't'      => trans('financial_doctor_payouts'),
            'shared' => trans('financial_shared'),
        ]);
    }

    public function store(DoctorPayoutRuleRequest $request): RedirectResponse|JsonResponse
    {
        $entity  = $this->authorizeFinancial();
        $created = $this->rules->create((string) $entity->id, $request->validated());

        $message = $created->count() > 1
            ? __('financial_doctor_payouts.flash.rules_created', ['count' => $created->count()])
            : __('financial_doctor_payouts.flash.rule_created');

        return $this->respond($request, $message, $created->map(fn (DoctorPayoutRule $r) => $this->row($r->load($this->rowRelations())))->values()->all());
    }

    public function update(DoctorPayoutRuleRequest $request, DoctorPayoutRule $rule): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($rule, $entity);

        $data    = $request->validated();
        $updated = $this->rules->update($rule, $data);

        $message = ($data['effective_from'] ?? null) !== null
            ? __('financial_doctor_payouts.flash.rule_superseded', ['date' => CarbonImmutable::parse((string) $data['effective_from'])->locale(app()->getLocale())->isoFormat('L')])
            : __('financial_doctor_payouts.flash.rule_updated');

        return $this->respond($request, $message, $this->row($updated->load($this->rowRelations())));
    }

    public function destroy(Request $request, DoctorPayoutRule $rule): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($rule, $entity);

        $this->rules->delete($rule);

        return $this->respond($request, __('financial_doctor_payouts.flash.rule_deleted'));
    }

    private function respond(Request $request, string $message, mixed $data = null): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'data' => $data]);
        }

        return $this->redirectToListing('panel.financial.doctor-payouts.rules.index', self::LISTING_PARAMS)
            ->with('message', $message);
    }

    /** @return list<string> */
    private function rowRelations(): array
    {
        return ['doctor.person:id,full_name', 'visitType:id,name', 'procedure:id,code,name', 'examType:id,name', 'covenant:id,name', 'participants'];
    }

    /** @return array<string, mixed> */
    private function row(DoctorPayoutRule $rule): array
    {
        [$itemKind, $itemId, $itemName] = match (true) {
            $rule->visit_type_id !== null => ['visit_type', $rule->visit_type_id, $rule->visitType?->name],
            $rule->procedure_id !== null  => ['procedure', $rule->procedure_id, $rule->procedure?->name],
            $rule->exam_type_id !== null  => ['exam_type', $rule->exam_type_id, $rule->examType?->name],
            default                       => [null, null, null],
        };

        return [
            'id'            => $rule->id,
            'doctor_id'     => $rule->doctor_id,
            'doctor_name'   => $rule->doctor?->person?->full_name,
            'service_type'  => $rule->service_type->value,
            'item_kind'     => $itemKind,
            'item_id'       => $itemId,
            'item_name'     => $itemName,
            'payer_scope'   => $rule->payer_scope->value,
            'covenant_id'   => $rule->covenant_id,
            'covenant_name' => $rule->covenant?->name,
            'calculation'   => $rule->calculation->value,
            'percentage'    => $rule->calculation === DoctorPayoutCalculation::Percentage ? (float) $rule->percentage : null,
            'fixed_amount'  => $rule->calculation === DoctorPayoutCalculation::Fixed ? (float) $rule->fixed_amount : null,
            'valid_from'    => $rule->valid_from?->toDateString(),
            'valid_until'   => $rule->valid_until?->toDateString(),
            'active'        => (bool) $rule->active,
            'notes'         => $rule->notes,
            'is_general'    => $rule->doctor_id === null && $rule->payer_scope === DoctorPayoutPayerScope::Any && $itemKind === null,
            // Divisão (E4): % da regra = parte do grupo; a clínica fica com o restante.
            'participants' => $rule->participants->map(fn ($participant) => [
                'role'       => $participant->role,
                'doctor_id'  => $participant->doctor_id,
                'percentage' => (float) $participant->percentage,
            ])->values()->all(),
        ];
    }

    private function paginateClamped(Builder $query, int $perPage): LengthAwarePaginator
    {
        $paginator = (clone $query)->paginate($perPage);

        if ($paginator->isEmpty() && $paginator->currentPage() > 1 && $paginator->lastPage() >= 1) {
            $paginator = $query->paginate($perPage, ['*'], 'page', $paginator->lastPage());
        }

        return $paginator;
    }

    private function queryText(Request $request, string $key, string $default = ''): string
    {
        $value = $request->query($key, $default);

        return is_string($value) ? Str::of($value)->trim()->toString() : $default;
    }
}
