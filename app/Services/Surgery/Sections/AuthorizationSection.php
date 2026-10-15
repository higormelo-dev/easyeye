<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Enums\Surgery\{AuthorizationEventType, AuthorizationStatus, SurgicalCaseStatus};
use App\Models\Surgery\{SurgicalAuthorizationEvent, SurgicalCase};
use App\Services\Surgery\{SurgeryAuthorizationService, SurgicalCaseView};

/** Aba "Autorização": estado atual, o que falta no pedido, log de eventos e as ações permitidas agora. */
final class AuthorizationSection implements SurgeryShowSection
{
    public function __construct(private readonly SurgeryAuthorizationService $authorization)
    {
    }

    public function key(): string
    {
        return 'authorization';
    }

    public function order(): int
    {
        return 20;
    }

    public function label(): string
    {
        return (string) __('surgery.tabs.authorization');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        return true;
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $case->loadMissing(['authorizationEvents.recorder', 'procedures', 'materials']);

        $status  = $case->status;
        $manage  = $can['manage'];
        $pending = $status === SurgicalCaseStatus::AwaitingAuthorization;

        // Respostas já recebidas só valem dentro da rodada em andamento (autorização vencida, a ser
        // renovada, começa outra: o servidor refaz tudo).
        $roundOpen = $pending && $case->authorization_status === AuthorizationStatus::Pending;

        return [
            'state'    => SurgicalCaseView::authorization($case),
            'problems' => in_array($status, [SurgicalCaseStatus::Draft, SurgicalCaseStatus::Denied], true)
                ? $this->authorization->requestProblems($case)
                : [],
            'events' => $case->authorizationEvents->map(fn (SurgicalAuthorizationEvent $e) => [
                'id'               => (string) $e->getKey(),
                'type'             => $e->type->value,
                'label'            => $e->type->label(),
                'occurred_at'      => $e->occurred_at?->toIso8601String(),
                'number'           => $e->authorization_number,
                'valid_until'      => $e->valid_until?->toDateString(),
                'authorized_items' => $e->authorized_items,
                'denial_reason'    => $e->denial_reason,
                'protocol_number'  => $e->protocol_number,
                'channel'          => $e->channel,
                'note'             => $e->note,
                'recorded_by'      => $e->recorder?->name,
            ])->all(),
            'actions' => [
                'request'  => $manage && in_array($status, [SurgicalCaseStatus::Draft, SurgicalCaseStatus::Denied], true),
                'appeal'   => $status === SurgicalCaseStatus::Denied,
                'withdraw' => $manage && $pending,
                'respond'  => $manage && $pending,
                'waive'    => $manage && in_array($status, [SurgicalCaseStatus::Draft, SurgicalCaseStatus::AwaitingAuthorization, SurgicalCaseStatus::Denied], true),
                'note'     => $manage && ! $status->isFinal(),
            ],
            // Itens do pedido para a resposta parcial (o que a operadora liberou, por item). `locked`:
            // a operadora já respondeu esse item nesta rodada (resposta de uma das guias) — o registro
            // manual vale só para o que ainda espera resposta e não o refaz.
            'items' => [
                ...$case->procedures->map(fn ($p) => ['kind' => 'procedure', 'id' => (string) $p->getKey(), 'label' => trim(($p->tuss_code ? $p->tuss_code . ' — ' : '') . $p->description), 'requested' => (int) $p->quantity, 'authorized' => $p->authorized_quantity, 'locked' => $roundOpen && $p->authorized_quantity !== null])->all(),
                ...$case->materials->map(fn ($m) => ['kind' => 'material', 'id' => (string) $m->getKey(), 'label' => $m->description, 'requested' => (int) $m->quantity, 'authorized' => $m->authorized_quantity, 'locked' => $roundOpen && $m->authorized_quantity !== null])->all(),
            ],
            'channels'    => ['phone', 'email', 'portal', 'tiss', 'in_person', 'other'],
            'event_types' => array_map(fn (AuthorizationEventType $t) => ['value' => $t->value, 'label' => $t->label()], SurgeryAuthorizationService::RECORDABLE),
        ];
    }
}
