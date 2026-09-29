<?php

declare(strict_types=1);

namespace App\Http\Controllers\Financial\DoctorPayouts;

use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\{AuthorizesDoctorPayouts, RendersDoctorPayoutStatement};
use App\Http\Requests\Financial\{CloseDoctorPayoutRequest, DoctorPayoutReasonRequest, PayDoctorPayoutRequest};
use App\Models\DoctorPayout;
use App\Services\Financial\DoctorPayouts\{DoctorPayoutClosingService, DoctorPayoutExporter, DoctorPayoutOptions, DoctorPayoutPresenter};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request, Response};
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Financeiro › Repasse médico › Fechamentos: histórico, fechar período,
 * demonstrativo (tela, PDF e planilha) e reabrir (admin, com motivo).
 */
class DoctorPayoutClosingsController extends Controller
{
    use AuthorizesDoctorPayouts;
    use RendersDoctorPayoutStatement;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly DoctorPayoutClosingService $closing,
        private readonly DoctorPayoutPresenter $presenter,
        private readonly DoctorPayoutOptions $options,
        private readonly DoctorPayoutExporter $exporter,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity   = $this->authorizeFinancial();
        $entityId = (string) $entity->id;
        $doctors  = $this->options->doctors($entityId);

        $doctor = $request->query('doctor');
        $doctor = is_string($doctor) && in_array($doctor, array_column($doctors, 'id'), true) ? $doctor : '';

        $status = $request->query('status');
        $status = is_string($status) && in_array($status, DoctorPayoutStatus::values(), true) ? $status : '';

        $query = DoctorPayout::query()
            ->where('doctor_payouts.entity_id', $entityId)
            ->when($doctor !== '', fn (Builder $q) => $q->where('doctor_payouts.doctor_id', $doctor))
            ->when($status !== '', fn (Builder $q) => $q->where('doctor_payouts.status', $status))
            ->select('doctor_payouts.*')
            // Fechamento complementar: outro fechamento válido do mesmo médico,
            // feito antes, com período sobreposto (itens lançados depois).
            ->selectRaw(<<<'SQL'
                (doctor_payouts.status <> 'cancelled' AND EXISTS (
                    SELECT 1 FROM doctor_payouts o
                    WHERE o.entity_id = doctor_payouts.entity_id
                      AND o.doctor_id = doctor_payouts.doctor_id
                      AND o.id <> doctor_payouts.id
                      AND o.status <> 'cancelled'
                      AND o.closed_at < doctor_payouts.closed_at
                      AND o.period_start <= doctor_payouts.period_end
                      AND o.period_end >= doctor_payouts.period_start
                )) AS is_complementary
                SQL)
            ->orderByDesc('doctor_payouts.period_end')
            ->orderByDesc('doctor_payouts.closed_at')
            ->orderBy('doctor_payouts.id');

        $payouts = $query->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DoctorPayout $payout) => $this->presenter->payoutSummary($payout)
                + ['is_complementary' => (bool) $payout->getAttribute('is_complementary')]);

        return Inertia::render('Panel/Financial/DoctorPayouts/Closings', [
            'breadcrumbs' => $this->payoutBreadcrumbs(__('financial_doctor_payouts.tabs.closings')),
            'tabs'        => $this->payoutTabs(),
            'payouts'     => $payouts,
            'filters'     => ['doctor' => $doctor, 'status' => $status],
            'options'     => ['doctors' => $doctors, 'statuses' => DoctorPayoutStatus::values()],
            'routes'      => [
                'index' => route('panel.financial.doctor-payouts.closings.index'),
                'show'  => route('panel.financial.doctor-payouts.closings.show', ['__ID__']),
                'pdf'   => route('panel.financial.doctor-payouts.closings.pdf', ['__ID__']),
            ],
            't'      => trans('financial_doctor_payouts'),
            'shared' => trans('financial_shared'),
        ]);
    }

    public function store(CloseDoctorPayoutRequest $request): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();

        $payout = $this->closing->close(
            (string) $entity->id,
            (string) $request->validated('doctor_id'),
            $request->validated(),
            auth()->id(),
        );

        $message = __('financial_doctor_payouts.flash.closed', ['code' => $payout->code]);
        $url     = route('panel.financial.doctor-payouts.closings.show', $payout);

        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'data' => ['id' => $payout->id, 'code' => $payout->code, 'url' => $url]]);
        }

        return redirect()->to($url)->with('message', $message);
    }

    public function show(DoctorPayout $payout): InertiaResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);

        $isAdmin = $this->isEntityAdmin($entity);

        return Inertia::render('Panel/Financial/DoctorPayouts/Show', [
            'breadcrumbs' => $this->payoutBreadcrumbs((string) $payout->code),
            'tabs'        => $this->payoutTabs(),
            'statement'   => $this->presenter->statement($payout),
            'permissions' => [
                'can_adjust'  => $payout->isClosed(),
                'can_pay'     => $payout->isClosed(),
                'can_reverse' => $payout->isPaid() && $isAdmin,
                'can_reopen'  => $payout->isClosed() && $isAdmin,
                'is_admin'    => $isAdmin,
            ],
            'payment_methods' => array_map(fn (string $method) => [
                'value' => $method,
                'label' => __("financial_doctor_payouts.payment_methods.{$method}"),
            ], PayDoctorPayoutRequest::METHODS),
            'today'         => now()->toDateString(),
            'reason_limits' => ['min' => DoctorPayoutReasonRequest::REASON_MIN, 'max' => DoctorPayoutReasonRequest::REASON_MAX],
            // Textos do ConfirmationWithReasonModal (lidos de `t_hardening`):
            // mínimo de 10 caracteres e exemplo de repasse, em vez do texto
            // genérico do manager (mínimo 20) — mesmo ajuste do fechamento de caixa.
            't_hardening' => fn () => array_merge(
                (array) trans('manager_hardening'),
                (array) trans('financial_doctor_payouts.reason_modal'),
            ),
            'routes' => [
                'closings' => route('panel.financial.doctor-payouts.closings.index'),
                'apuracao' => route('panel.financial.doctor-payouts.index', [
                    'doctor' => $payout->doctor_id,
                    'from'   => $payout->period_start->toDateString(),
                    'to'     => $payout->period_end->toDateString(),
                ]),
                'pdf'                 => route('panel.financial.doctor-payouts.closings.pdf', $payout),
                'export'              => route('panel.financial.doctor-payouts.closings.export', $payout),
                'pay'                 => route('panel.financial.doctor-payouts.closings.payment.store', $payout),
                'reverse'             => route('panel.financial.doctor-payouts.closings.payment.destroy', $payout),
                'reopen'              => route('panel.financial.doctor-payouts.closings.destroy', $payout),
                'adjustments_store'   => route('panel.financial.doctor-payouts.closings.adjustments.store', $payout),
                'adjustments_destroy' => route('panel.financial.doctor-payouts.closings.adjustments.destroy', [$payout, '__ID__']),
                'cash_flow'           => $payout->cash_entry_id === null ? null : route('panel.financial.cash-flow.index', [
                    'from' => $payout->paid_at?->toDateString(),
                    'to'   => $payout->paid_at?->toDateString(),
                ]),
            ],
            't'      => trans('financial_doctor_payouts'),
            'shared' => trans('financial_shared'),
        ]);
    }

    /** Reabrir (admin, com motivo): cancela o fechamento e devolve os itens à produção pendente. */
    public function destroy(DoctorPayoutReasonRequest $request, DoctorPayout $payout): RedirectResponse|JsonResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);
        abort_unless($this->isEntityAdmin($entity), 403, __('financial_doctor_payouts.admin_only'));

        $this->closing->reopen($payout, (string) $request->validated('reason'), auth()->id());

        $message = __('financial_doctor_payouts.flash.reopened');

        if ($request->wantsJson()) {
            return response()->json(['message' => $message]);
        }

        return back()->with('message', $message);
    }

    public function pdf(Request $request, DoctorPayout $payout): SymfonyResponse|RedirectResponse
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);

        return $this->statementPdf($request, $entity, $payout, $this->presenter, $this->exporter);
    }

    public function export(Request $request, DoctorPayout $payout): Response
    {
        $entity = $this->authorizeFinancial();
        $this->assertBelongsToEntity($payout, $entity);

        $rows   = collect($this->presenter->statement($payout)['groups'])->flatMap(fn (array $group) => $group['items'])->values()->all();
        $format = DoctorPayoutExporter::normalizeFormat($request->query('format'));

        $this->exporter->audit($request, (string) $entity->id, 'doctor_payout_statement', $format, [
            'payout_id' => $payout->id,
            'code'      => $payout->code,
        ], count($rows));

        return $this->exporter->download(
            $format,
            $this->presenter->exportRows($rows),
            sprintf('%s_%s', __('financial_doctor_payouts.export_filename'), $payout->code),
            (string) $payout->code,
        );
    }
}
