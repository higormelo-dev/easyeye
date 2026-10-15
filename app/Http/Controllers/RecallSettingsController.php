<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Recall\Enums\RecallExamKey;
use App\Domains\Recall\Exceptions\ProtocolActivationException;
use App\Domains\Recall\Models\RecallClinicSettings;
use App\Domains\Recall\Services\{ExamMappingService, ProtocolActivationService, RecallInviteGuard, RecallInvitePacing, RecallSettingsPresenter};
use App\Domains\Recall\Support\RecallRuleLabels;
use App\Enums\EntityGate;
use App\Http\Requests\Recall\{ActivateRecallProtocolRequest, DeactivateRecallProtocolRequest, RecallExamMappingsRequest, RecallGeneralSettingsRequest};
use App\Models\Entity;
use App\Support\CurrentDoctor;
use Carbon\CarbonImmutable;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\Gate;
use Inertia\{Inertia, Response as InertiaResponse};
use InvalidArgumentException;

/**
 * Configuração dos retornos da clínica (Fase 6): ativar/desativar os protocolos validados, o retorno pedido pelo
 * médico e o mapeamento dos exames. Ver é de quem opera o recall (OperateRecall); ATIVAR e DESATIVAR protocolo é
 * ato clínico do médico com cadastro ativo (DecideRecall) — a ativação grava o médico responsável e o retrato do
 * que ele viu. Retorno pedido pelo médico e mapeamento: administrador ou médico.
 */
class RecallSettingsController extends Controller
{
    public function __construct(
        private readonly RecallSettingsPresenter $presenter,
        private readonly ProtocolActivationService $activation,
        private readonly ExamMappingService $mappings,
        private readonly RecallInviteGuard $inviteGuard,
        private readonly RecallInvitePacing $pacing,
    ) {
    }

    public function index(Request $request): InertiaResponse
    {
        $entity = $this->entity();
        Gate::authorize(EntityGate::OperateRecall->value, $entity);

        $entityId  = (string) $entity->id;
        $canDecide = Gate::allows(EntityGate::DecideRecall->value, $entity);

        return Inertia::render('Panel/Recall/Settings', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('recall_list.title'), 'url' => route('panel.recall.index'), 'active' => false],
                ['label' => __('recall_settings.title'), 'url' => '#', 'active' => true],
            ],
            'general' => $this->general($entityId),
            // O que está do lado do EasyEye para o convite por WhatsApp (pausa, chave da clínica no Manager, número):
            // a clínica só consegue ligar a própria chave quando não há impedimento aqui.
            'invites'   => $this->invitesState($entityId),
            'protocols' => $this->presenter->protocols($entityId, app()->getLocale()),
            'exams'     => [
                'keys'  => array_map(static fn (RecallExamKey $key): array => ['key' => $key->value, 'label' => $key->label()], RecallExamKey::cases()),
                'types' => [
                    ...array_map(static fn (array $type): array => $type + ['unavailable' => false], $this->mappings->availableTypes($entityId)),
                    ...array_map(static fn (array $type): array => $type + ['unavailable' => true], $this->mappings->retiredMapped($entityId)),
                ],
                'mappings'    => $this->mappings->current($entityId),
                'suggestions' => $this->mappings->suggestions($entityId),
            ],
            'canDecide'         => $canDecide,
            'canConfigure'      => $canDecide || Gate::allows(EntityGate::ManageSettings->value, $entity),
            'isDoctor'          => CurrentDoctor::isDoctorProfile(),
            'doctorMissing'     => CurrentDoctor::issuerMissing(), // perfil médico sem cadastro de médico ATIVO: é o que o Gate DecideRecall exige
            'doctorMissingText' => CurrentDoctor::missingMessage(),
            'ruleT'             => RecallRuleLabels::texts(),
            't'                 => trans('recall_settings'),
        ]);
    }

    public function updateGeneral(RecallGeneralSettingsRequest $request): RedirectResponse
    {
        $settings = RecallClinicSettings::forEntity((string) session('selected_entity_id'));
        $wasOn    = (bool) $settings->whatsapp_invites_enabled;

        $settings->fill(['doctor_order_enabled' => $request->boolean('doctor_order_enabled')]);
        $settings->fill($request->safe()->only([
            'invite_window_start', 'invite_window_end', 'invite_weekdays', 'min_days_between_invites',
            'max_auto_invites_per_cycle', 'daily_invite_cap', 'auto_invite_max_overdue_days',
        ]));

        if ($request->has('whatsapp_invites_enabled')) {
            $settings->whatsapp_invites_enabled = $request->boolean('whatsapp_invites_enabled');

            // Ligar o convite (de desligado para ligado) recomeça o ramp-up: o teto do dia sobe devagar.
            if ($settings->whatsapp_invites_enabled && ! $wasOn) {
                $settings->ramp_started_on = CarbonImmutable::now()->toDateString();
            }
        }

        // A linha só nasce na primeira mudança: `forEntity` devolve o padrão (ainda sem gravar) quando não existe.
        $settings->save();

        return back()->with('success', __('recall_settings.done.general'));
    }

    public function activate(ActivateRecallProtocolRequest $request, string $protocol): RedirectResponse
    {
        $entity = $this->entity();

        try {
            $this->activation->activate(
                $entity,
                $protocol,
                (string) $request->validated('version_id'),
                ['tasks' => (array) $request->input('tasks', []), 'auto_contact' => $request->boolean('auto_contact')],
                $request->boolean('acknowledged'),
                $request->user(),
                CurrentDoctor::doctor(),
            );
        } catch (ProtocolActivationException $e) {
            return $this->refused($e);
        }

        return back()->with('success', __('recall_settings.done.activated'));
    }

    public function deactivate(DeactivateRecallProtocolRequest $request, string $protocol): RedirectResponse
    {
        try {
            $this->activation->deactivate($this->entity(), $protocol, (string) $request->validated('reason'), $request->user());
        } catch (ProtocolActivationException $e) {
            return $this->refused($e);
        }

        return back()->with('success', __('recall_settings.done.deactivated'));
    }

    public function updateExamMappings(RecallExamMappingsRequest $request): RedirectResponse
    {
        try {
            $this->mappings->sync((string) session('selected_entity_id'), (array) $request->validated('mappings'), $request->user());
        } catch (InvalidArgumentException) {
            return back()->with('error', __('recall_settings.errors.invalid_mapping'));
        }

        return back()->with('success', __('recall_settings.done.mappings'));
    }

    // ── infraestrutura ──────────────────────────────────────────────────────

    /**
     * A configuração geral que a tela edita (retorno do médico + ritmo do convite por WhatsApp).
     *
     * @return array<string, mixed>
     */
    private function general(string $entityId): array
    {
        $settings = RecallClinicSettings::forEntity($entityId);
        $now      = CarbonImmutable::now();

        return [
            'doctor_order_enabled'         => (bool) $settings->doctor_order_enabled,
            'whatsapp_invites_enabled'     => (bool) $settings->whatsapp_invites_enabled,
            'invite_window_start'          => (string) $settings->invite_window_start,
            'invite_window_end'            => (string) $settings->invite_window_end,
            'invite_weekdays'              => array_map('intval', (array) $settings->invite_weekdays),
            'min_days_between_invites'     => (int) $settings->min_days_between_invites,
            'max_auto_invites_per_cycle'   => (int) $settings->max_auto_invites_per_cycle,
            'daily_invite_cap'             => (int) $settings->daily_invite_cap,
            'auto_invite_max_overdue_days' => (int) $settings->auto_invite_max_overdue_days,
            // O teto de HOJE (ramp-up aplicado) e quantos já saíram: a clínica entende por que saem poucos no começo.
            'cap_today'      => $this->pacing->dailyCap($settings, $now),
            'sent_today'     => $this->pacing->sentToday($entityId, $now),
            'min_days_floor' => (int) config('recall.invites.min_days_between_invites_floor', 7),
        ];
    }

    /**
     * @return array{available: bool, blocker: ?string, label: ?string}
     */
    private function invitesState(string $entityId): array
    {
        $blocker = $this->inviteGuard->providerBlocker($entityId);

        return ['available' => $blocker === null, 'blocker' => $blocker?->value, 'label' => $blocker?->label()];
    }

    /** Recusa de negócio: erro de parâmetro vai ao campo; o resto volta como aviso traduzido. */
    private function refused(ProtocolActivationException $e): RedirectResponse
    {
        if ($e->reason() === ProtocolActivationException::INVALID_PARAMETERS) {
            $errors = [];

            foreach ($e->errors() as $field => $code) {
                $errors[$field] = __('recall_settings.errors.' . $code);
            }

            return back()->withErrors($errors);
        }

        return back()->with('error', __('recall_settings.errors.' . $e->reason()));
    }

    private function entity(): Entity
    {
        return Entity::query()->findOrFail((string) session('selected_entity_id'));
    }
}
