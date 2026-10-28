<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DoctorPayout\DoctorPayoutStatus;
use App\Http\Controllers\Financial\DoctorPayouts\Concerns\RendersDoctorPayoutStatement;
use App\Models\{Doctor, DoctorPayout, Entity};
use App\Services\Financial\DoctorPayouts\{DoctorPayoutExporter, DoctorPayoutPresenter};
use Illuminate\Http\{RedirectResponse, Request};
use Inertia\{Inertia, Response as InertiaResponse};
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * "Meus repasses": o médico logado vê só os PRÓPRIOS fechamentos (fechados e
 * pagos) e o demonstrativo — e só se a clínica ligou a opção
 * (entities.doctor_payouts_visible). Opção desligada, usuário sem cadastro de
 * médico na clínica ou fechamento de outro médico/cancelado: 404 (não revela
 * que o recurso existe). Produção pendente nunca aparece aqui.
 */
class MyPayoutsController extends Controller
{
    use RendersDoctorPayoutStatement;

    private const PER_PAGE = 20;

    public function __construct(
        private readonly DoctorPayoutPresenter $presenter,
        private readonly DoctorPayoutExporter $exporter,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        [, $doctor, $entity] = $this->resolveDoctor($request);

        $payouts = DoctorPayout::query()
            ->where('entity_id', $entity->id)
            ->where('doctor_id', $doctor->id)
            ->whereIn('status', DoctorPayoutStatus::valid())
            ->orderByDesc('period_end')
            ->orderByDesc('closed_at')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (DoctorPayout $payout) => [
                'id'           => $payout->id,
                'code'         => $payout->code,
                'period_start' => $payout->period_start->toDateString(),
                'period_end'   => $payout->period_end->toDateString(),
                'items_count'  => $payout->items_count,
                // Regime do fechamento: rótulo da produção (recebido × cobrado).
                'basis'        => $payout->basis->value,
                'gross_amount' => (float) $payout->gross_amount,
                'total_amount' => (float) $payout->total_amount,
                'status'       => $payout->status->value,
                'paid_at'      => $payout->paid_at?->toDateString(),
                'paid_amount'  => (float) ($payout->paid_amount ?? 0),
            ]);

        return Inertia::render('Panel/MyPayouts/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial_doctor_payouts.my_title'), 'url' => '#', 'active' => true],
            ],
            'payouts' => $payouts,
            'routes'  => [
                'index' => route('panel.my-payouts.index'),
                'show'  => route('panel.my-payouts.show', ['__ID__']),
                'pdf'   => route('panel.my-payouts.pdf', ['__ID__']),
            ],
            't' => trans('financial_doctor_payouts'),
        ]);
    }

    public function show(Request $request, DoctorPayout $payout): InertiaResponse
    {
        [, $doctor, $entity] = $this->resolveDoctor($request);
        $this->assertOwn($payout, $entity, $doctor);

        $this->exporter->auditView($request, (string) $entity->id, $payout, forDoctor: true);

        return Inertia::render('Panel/MyPayouts/Show', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('financial_doctor_payouts.my_title'), 'url' => route('panel.my-payouts.index'), 'active' => false],
                ['label' => (string) $payout->code, 'url' => '#', 'active' => true],
            ],
            'statement' => $this->presenter->statement($payout, forDoctor: true),
            'routes'    => [
                'index' => route('panel.my-payouts.index'),
                'pdf'   => route('panel.my-payouts.pdf', $payout),
            ],
            't' => trans('financial_doctor_payouts'),
        ]);
    }

    public function pdf(Request $request, DoctorPayout $payout): SymfonyResponse|RedirectResponse
    {
        [, $doctor, $entity] = $this->resolveDoctor($request);
        $this->assertOwn($payout, $entity, $doctor);

        return $this->statementPdf($request, $entity, $payout, $this->presenter, $this->exporter, forDoctor: true);
    }

    /**
     * @return array{0: null, 1: Doctor, 2: Entity}
     */
    private function resolveDoctor(Request $request): array
    {
        $entity = Entity::query()->findOrFail(session('selected_entity_id'));

        abort_unless((bool) $entity->doctor_payouts_visible, 404);

        $doctor = $request->user()?->entityUserFor($entity)?->doctor;

        abort_if($doctor === null, 404);

        return [null, $doctor, $entity];
    }

    private function assertOwn(DoctorPayout $payout, Entity $entity, Doctor $doctor): void
    {
        abort_unless(
            (string) $payout->entity_id === (string) $entity->id
            && (string) $payout->doctor_id === (string) $doctor->id
            && ! $payout->isCancelled(),
            404,
        );
    }
}
