<?php

declare(strict_types=1);

namespace App\Domains\Recall\Services;

use App\Domains\Recall\Enums\InviteResponse;
use App\Domains\Recall\Models\{PatientRecallEvent, RecallInvite};
use Illuminate\Support\Facades\DB;

/**
 * A resposta do PACIENTE ao convite de retorno (botões "Quero agendar" / "Já agendei"): grava na onda e deixa um
 * evento em cada retorno coberto, para a recepção ver na lista e no histórico. Não muda o retorno — quem agenda é a
 * equipe (o vínculo com a agenda fecha o retorno). Idempotente: o mesmo botão tocado duas vezes não repete o evento.
 */
final class RecallInviteResponder
{
    public function record(string $inviteId, InviteResponse $response): void
    {
        DB::transaction(function () use ($inviteId, $response): void {
            $invite = RecallInvite::query()->withoutGlobalScopes()->whereKey($inviteId)->lockForUpdate()->first();

            if ($invite === null || $invite->response === $response) {
                return;
            }

            $invite->forceFill(['response' => $response->value, 'responded_at' => now()])->save();

            foreach ($invite->recalls()->withoutGlobalScopes()->get() as $recall) {
                PatientRecallEvent::query()->withoutGlobalScopes()->create([
                    'entity_id'     => $recall->entity_id,
                    'patient_id'    => $recall->patient_id,
                    'recall_id'     => $recall->id,
                    'event'         => 'invite_responded',
                    'actor_user_id' => null,
                    'actor_label'   => 'patient',
                    'meta'          => ['invite_id' => $invite->id, 'response' => $response->value],
                ]);
            }
        });
    }
}
