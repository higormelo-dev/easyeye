<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Recall\Enums\RecallContactOutcome;
use App\Domains\Recall\Exceptions\{RecallActionException, RecallInviteException};
use App\Domains\Recall\Models\{PatientRecall, RecallClinicProtocol, RecallClinicSettings};
use App\Domains\Recall\Services\{RecallActionService, RecallInviteOverview, RecallInviteService, RecallListService};
use App\Enums\{DataAccessPurpose, EntityGate};
use App\Http\Requests\Recall\{RecallContactRequest, RecallDismissRequest, RecallDoneElsewhereRequest, RecallInviteRequest, RecallReopenRequest, RecallSnoozeRequest};
use App\Models\Entity;
use App\Support\{CurrentDoctor, PatientAccess};
use App\Traits\LogsDataAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\{JsonResponse, RedirectResponse, Request};
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};

/**
 * Tela "Retornos" (Fase 6): quem precisa voltar — a lista de retornos da clínica, o contato, o adiamento, a
 * dispensa e o "feito fora". Rota no grupo da Agenda (admin, médico, secretária) atrás da feature
 * `has_patient_recall`; a regra de quem opera é o Gate OperateRecall.
 *
 * Minimização por perfil: só o perfil MÉDICO vê a condição clínica de cada retorno; secretária e admin veem o
 * que fazer ("Campo visual", "Consulta de retorno"), não o diagnóstico. Telefone só para quem tem acesso a
 * Pacientes. As ações gravam o acesso (LGPD art. 37) e o evento com quem fez.
 */
class RecallController extends Controller
{
    use LogsDataAccess;

    public function __construct(
        private readonly RecallListService $list,
        private readonly RecallActionService $actions,
        private readonly RecallInviteService $invites,
        private readonly RecallInviteOverview $overview,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity = $this->entity();
        Gate::authorize(EntityGate::OperateRecall->value, $entity);

        $entityId = (string) $entity->id;
        $today    = CarbonImmutable::now()->startOfDay();
        $isDoctor = CurrentDoctor::isDoctorProfile();
        $phones   = app(PatientAccess::class)->current();

        $view   = in_array($request->query('view'), RecallListService::VIEWS, true) ? (string) $request->query('view') : RecallListService::VIEW_TO_CONTACT;
        $source = in_array($request->query('source'), RecallListService::SOURCES, true) ? (string) $request->query('source') : null;
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 100);

        $paginator = $this->list->paginate($entityId, $view, $source, $search, max(1, (int) $request->query('page', 1)), $today);
        $paginator->withQueryString()->setPath(route('panel.recall.index'));

        $channel = $this->overview->channel($entityId);
        $rows    = $this->overview->decorate($this->list->present($paginator->items(), $entityId, $phones, $isDoctor, $today), $entityId, $channel['available']);

        return Inertia::render('Panel/Recall/Index', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('recall_list.title'), 'url' => '#', 'active' => true],
            ],
            'view'            => $view,
            'source'          => $source,
            'search'          => $search,
            'counts'          => $this->list->counts($entityId, $today),
            'rows'            => array_merge($paginator->toArray(), ['data' => $rows]),
            'today'           => $today->toDateString(),
            'snoozeMaxDays'   => RecallActionService::MAX_SNOOZE_DAYS,
            'contactOutcomes' => array_map(static fn (RecallContactOutcome $outcome): array => ['value' => $outcome->value, 'label' => $outcome->label()], RecallContactOutcome::cases()),
            'isDoctor'        => $isDoctor,
            'phonesVisible'   => $phones,
            'setup'           => $this->setup($entityId),
            // O canal de convite por WhatsApp da clínica (aberto, ou por que não): a tela só oferece "Enviar convite" aberto.
            'invites' => $channel,
            't'       => trans('recall_list'),
        ]);
    }

    public function contact(RecallContactRequest $request, string $recall): RedirectResponse
    {
        return $this->run($recall, fn (string $entityId) => $this->actions->contact($entityId, $recall, RecallContactOutcome::from((string) $request->validated('outcome')), $request->validated('note'), $request->user()), 'contacted');
    }

    public function snooze(RecallSnoozeRequest $request, string $recall): RedirectResponse
    {
        return $this->run($recall, fn (string $entityId) => $this->actions->snooze($entityId, $recall, CarbonImmutable::createFromFormat('Y-m-d', (string) $request->validated('until')), $request->validated('reason'), $request->user()), 'snoozed');
    }

    public function dismiss(RecallDismissRequest $request, string $recall): RedirectResponse
    {
        return $this->run($recall, fn (string $entityId) => $this->actions->dismiss($entityId, $recall, (string) $request->validated('reason'), $request->user()), 'dismissed');
    }

    public function reopen(RecallReopenRequest $request, string $recall): RedirectResponse
    {
        return $this->run($recall, fn (string $entityId) => $this->actions->reopen($entityId, $recall, $request->user()), 'reopened');
    }

    public function doneElsewhere(RecallDoneElsewhereRequest $request, string $recall): RedirectResponse
    {
        return $this->run($recall, fn (string $entityId) => $this->actions->doneElsewhere($entityId, $recall, CarbonImmutable::createFromFormat('Y-m-d', (string) $request->validated('date')), $request->user()), 'done_elsewhere');
    }

    /** A prévia do convite por WhatsApp (JSON): o texto exato, o telefone mascarado e, se não sai, por quê. */
    public function invitePreview(Request $request, string $recall): JsonResponse
    {
        Gate::authorize(EntityGate::OperateRecall->value, $this->entity());

        try {
            return response()->json($this->invites->preview((string) session('selected_entity_id'), $recall));
        } catch (RecallInviteException) {
            return response()->json(['message' => __('recall_list.errors.not_found')], 404);
        }
    }

    /** Manda o convite por WhatsApp (uma pessoa decidiu): a onda cobre todos os retornos do paciente que podem ser convidados. */
    public function invite(RecallInviteRequest $request, string $recall): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        try {
            $invite = $this->invites->inviteManual($entityId, $recall, $request->user());
        } catch (RecallInviteException $e) {
            return back()->with('error', match ($e->reason()) {
                RecallInviteException::BLOCKED => __('recall_invite.refused', ['reasons' => implode('; ', array_map(static fn ($blocker): string => $blocker->label(), $e->blockers()))]),
                default                        => __('recall_invite.errors.' . $e->reason()),
            });
        }

        $recallRow = PatientRecall::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereKey($recall)->first();

        if ($recallRow !== null) {
            $this->logAccess($recallRow, DataAccessPurpose::Administrative, patientId: (string) $invite->patient_id, entityId: $entityId);
        }

        return back()->with('success', __('recall_list.done.invited'));
    }

    // ── infraestrutura ──────────────────────────────────────────────────────

    /**
     * Roda a ação da equipe: recusa de negócio volta para a lista com o motivo traduzido; sucesso grava o acesso
     * do ator ao dado do paciente e volta com a confirmação.
     *
     * @param callable(string): PatientRecall $action
     */
    private function run(string $recallId, callable $action, string $successKey): RedirectResponse
    {
        $entityId = (string) session('selected_entity_id');

        try {
            $row = $action($entityId);
        } catch (RecallActionException $e) {
            return back()->with('error', __('recall_list.errors.' . $e->reason()));
        }

        $this->logAccess($row, DataAccessPurpose::Administrative, patientId: (string) $row->patient_id, entityId: $entityId);

        return back()->with('success', __('recall_list.done.' . $successKey));
    }

    private function entity(): Entity
    {
        return Entity::query()->findOrFail((string) session('selected_entity_id'));
    }

    /**
     * O que a clínica já configurou — a tela usa para o estado vazio ("ative um protocolo", "tudo em dia").
     *
     * @return array{doctor_order_enabled: bool, protocols: int}
     */
    private function setup(string $entityId): array
    {
        return [
            'doctor_order_enabled' => RecallClinicSettings::forEntity($entityId)->doctor_order_enabled,
            'protocols'            => RecallClinicProtocol::query()->withoutGlobalScopes()->where('entity_id', $entityId)->whereNull('deactivated_at')->count(),
        ];
    }
}
