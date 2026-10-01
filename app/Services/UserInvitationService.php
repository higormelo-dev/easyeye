<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\{DoctorInvitation, Entity, EntityUser, EntityUserInvitation, SystemProfile, User};
use App\Notifications\UserClinicInvitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Usuário (secretária, financeiro, admin…) que já tem login no EasyEye
 * entrando em mais uma clínica — sem a clínica descobrir quem tem conta:
 *
 *  - invite(): grava o convite com o e-mail digitado SEMPRE (a resposta e a
 *    lista de pendentes não mudam); só quando há login elegível (existe, não
 *    é desta clínica) ele é ligado ao convite e recebe o e-mail;
 *  - accept(): só o dono do login, logado; entra com o perfil escolhido pela
 *    clínica. O login (nome/e-mail/senha) nunca é alterado.
 *
 * Médico tem fluxo próprio (CRM e cadastro clínico): DoctorInvitationService.
 */
class UserInvitationService
{
    public const ACCEPT_OK = DoctorInvitationService::ACCEPT_OK;

    public const ACCEPT_CLOSED = DoctorInvitationService::ACCEPT_CLOSED;

    public const ACCEPT_PLAN_LIMIT = DoctorInvitationService::ACCEPT_PLAN_LIMIT;

    public const ACCEPT_MEMBER = DoctorInvitationService::ACCEPT_MEMBER;

    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {
    }

    /**
     * Perfis que a clínica pode oferecer por convite (médico tem o seu).
     *
     * @return list<string>
     */
    public static function invitableRules(): array
    {
        return array_values(array_diff(
            array_keys(SystemProfile::labelMap(SystemProfile::CONTEXT_CLIENT)),
            ['doctor'],
        ));
    }

    /** A clínica ainda tem vaga de usuário no plano? */
    public function hasUserSlot(string $entityId): bool
    {
        return $this->featureGate->status($entityId, FeatureKey::MaxUsers)->allowed;
    }

    public function invite(string $entityId, string $email, string $rule, ?string $invitedBy): EntityUserInvitation
    {
        $email = mb_strtolower(trim($email));

        try {
            [$invitation, $notifyUser] = $this->upsertInvitation($entityId, $email, $rule, $invitedBy);
        } catch (UniqueConstraintViolationException) {
            // Dois envios simultâneos do mesmo e-mail: o outro criou o
            // pendente — refaz e atualiza esse.
            [$invitation, $notifyUser] = $this->upsertInvitation($entityId, $email, $rule, $invitedBy);
        }

        $notifyUser?->notify(new UserClinicInvitation($invitation));

        return $invitation;
    }

    /** @return array{0: EntityUserInvitation, 1: ?User} */
    private function upsertInvitation(string $entityId, string $email, string $rule, ?string $invitedBy): array
    {
        return DB::transaction(function () use ($entityId, $email, $rule, $invitedBy): array {
            // Só login com e-mail VERIFICADO (quem usa o painel já verificou):
            // conta criada com o e-mail de outra pessoa nunca recebe convite.
            $user     = User::query()->whereRaw('lower(email) = ?', [$email])->whereNotNull('email_verified_at')->first();
            $eligible = $user !== null && ! $this->isMember($user, $entityId);

            $invitation = EntityUserInvitation::query()
                ->where('entity_id', $entityId)
                ->where('email', $email)
                ->where('status', DoctorInvitation::STATUS_PENDING)
                ->lockForUpdate()
                ->first() ?? new EntityUserInvitation(['entity_id' => $entityId, 'email' => $email]);

            $invitation->fill([
                'user_id'    => $eligible ? $user->id : null,
                'invited_by' => $invitedBy,
                'rule'       => $rule,
                'status'     => DoctorInvitation::STATUS_PENDING,
                'expires_at' => now()->addDays(DoctorInvitation::VALID_DAYS),
            ]);

            // E-mail só para login elegível, e no máximo um a cada intervalo
            // (a clínica não usa o convite para lotar a caixa de ninguém).
            $notify = $eligible && ($invitation->notified_at === null
                || $invitation->notified_at->lte(now()->subHours(DoctorInvitation::RESEND_COOLDOWN_HOURS)));

            if ($notify) {
                $invitation->notified_at = now();
            }

            $invitation->save();

            return [$invitation, $notify ? $user : null];
        });
    }

    public function accept(EntityUserInvitation $invitation, User $user): string
    {
        return DB::transaction(function () use ($invitation, $user): string {
            $locked = EntityUserInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked || (string) $locked->user_id !== (string) $user->id || ! $locked->isOpen()) {
                return self::ACCEPT_CLOSED;
            }

            $entityId = (string) $locked->entity_id;

            if (! Entity::query()->whereKey($entityId)->exists()) {
                return self::ACCEPT_CLOSED;
            }

            // Vagas conferidas em fila: aceites simultâneos não estouram o plano.
            $this->lockClinicUsers($entityId);

            if ($this->isMember($user, $entityId)) {
                $this->close($locked, DoctorInvitation::STATUS_ACCEPTED);

                return self::ACCEPT_MEMBER;
            }

            if (! $this->hasUserSlot($entityId)) {
                return self::ACCEPT_PLAN_LIMIT;
            }

            // Quem convidou precisa continuar admin ativo da clínica — admin
            // removido/rebaixado não deixa convites valendo.
            if (! $this->inviterStillAdmin($locked)) {
                $this->close($locked, DoctorInvitation::STATUS_CANCELLED);

                return self::ACCEPT_CLOSED;
            }

            $entityUser = EntityUser::query()->withTrashed()
                ->where('user_id', $user->id)
                ->where('entity_id', $entityId)
                ->first();

            if ($entityUser?->trashed()) {
                $entityUser->restore();
            }

            $entityUser ??= new EntityUser(['entity_id' => $entityId, 'user_id' => $user->id, 'invited_by' => $locked->invited_by]);
            $entityUser->fill(['rule' => $locked->rule, 'active' => true, 'joined_at' => now()])->save();

            $this->close($locked, DoctorInvitation::STATUS_ACCEPTED);

            return self::ACCEPT_OK;
        });
    }

    public function decline(EntityUserInvitation $invitation, User $user): bool
    {
        return DB::transaction(function () use ($invitation, $user): bool {
            $locked = EntityUserInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked || (string) $locked->user_id !== (string) $user->id || ! $locked->isOpen()) {
                return false;
            }

            $this->close($locked, DoctorInvitation::STATUS_DECLINED);

            return true;
        });
    }

    public function cancel(EntityUserInvitation $invitation, string $entityId): bool
    {
        return DB::transaction(function () use ($invitation, $entityId): bool {
            $locked = EntityUserInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            // Recusado também: na lista da clínica ele continua "pendente" até
            // vencer (a recusa não pode revelar que a conta existe).
            if (! $locked || (string) $locked->entity_id !== $entityId
                || ! in_array($locked->status, [DoctorInvitation::STATUS_PENDING, DoctorInvitation::STATUS_DECLINED], true)) {
                return false;
            }

            $this->close($locked, DoctorInvitation::STATUS_CANCELLED);

            return true;
        });
    }

    /** Convites vencidos sem resposta → expirados (agendado diariamente). */
    public function expireOverdue(): int
    {
        $expired = 0;

        EntityUserInvitation::query()
            ->where('status', DoctorInvitation::STATUS_PENDING)
            ->where('expires_at', '<=', now())
            ->chunkById(200, function ($invitations) use (&$expired): void {
                foreach ($invitations as $invitation) {
                    $this->close($invitation, DoctorInvitation::STATUS_EXPIRED);
                    $expired++;
                }
            });

        return $expired;
    }

    /**
     * Retenção (LGPD): convites encerrados há mais de RETENTION_DAYS são
     * apagados — o e-mail digitado não fica guardado para sempre.
     */
    public function purgeClosed(): int
    {
        return EntityUserInvitation::query()
            ->where('status', '!=', DoctorInvitation::STATUS_PENDING)
            ->where('updated_at', '<', now()->subDays(DoctorInvitation::RETENTION_DAYS))
            ->delete();
    }

    private function inviterStillAdmin(EntityUserInvitation $invitation): bool
    {
        return $invitation->invited_by !== null && EntityUser::query()
            ->where('entity_id', $invitation->entity_id)
            ->where('user_id', $invitation->invited_by)
            ->where('rule', 'admin')
            ->where('active', true)
            ->exists();
    }

    private function close(EntityUserInvitation $invitation, string $status): void
    {
        $invitation->forceFill(['status' => $status, 'responded_at' => now()])->save();
    }

    private function isMember(User $user, string $entityId): bool
    {
        return EntityUser::query()->where('user_id', $user->id)->where('entity_id', $entityId)->exists();
    }

    /** PostgreSQL: advisory lock de transação por clínica (vagas de usuário). */
    private function lockClinicUsers(string $entityId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('select pg_advisory_xact_lock(?)', [
            (int) hexdec(substr(sha1('user-invitation-accept|' . $entityId), 0, 15)),
        ]);
    }
}
