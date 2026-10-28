<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FeatureKey;
use App\Models\{Doctor, DoctorInvitation, Entity, EntityUser, User};
use App\Notifications\DoctorClinicInvitation;
use Illuminate\Support\Facades\DB;

/**
 * Médico que já tem login no EasyEye (atende em outra clínica) entrando em
 * mais uma clínica — sem clínica nenhuma vincular ou alterar o login dele:
 *
 *  1. detect(): o cadastro da clínica reconhece o login existente (e-mail ou
 *     CPF de médico) e responde SÓ "já possui cadastro no EasyEye, mas não
 *     nesta clínica" — sem nome, e-mail ou clínicas.
 *  2. invite(): o convite vai para o e-mail do LOGIN EXISTENTE (nunca o
 *     digitado), com os dados que a clínica digitou criptografados.
 *  3. accept(): só o próprio médico, logado, aceita; entra na clínica com o
 *     cadastro (People) PRÓPRIO dela. Login e cadastros de outras clínicas
 *     ficam intocados.
 */
class DoctorInvitationService
{
    public const DETECT_NONE = 'none';

    public const DETECT_INVITE = 'invitable';

    public const DETECT_MEMBER = 'member';

    public const DETECT_CONFLICT = 'conflict';

    public const DETECT_TAKEN = 'taken';

    public const ACCEPT_OK = 'accepted';

    public const ACCEPT_CLOSED = 'closed';

    public const ACCEPT_PLAN_LIMIT = 'plan_limit';

    public const ACCEPT_MEMBER = 'already_member';

    public const ACCEPT_CONFLICT = 'conflict';

    public function __construct(
        private readonly DoctorService $doctorService,
        private readonly FeatureGateService $featureGate,
    ) {
    }

    /**
     * Quem é o dono do e-mail/CPF informados no cadastro de médico?
     *  - none: ninguém → cadastro normal;
     *  - invitable: login de MÉDICO de outra clínica → convite;
     *  - member / taken: já está nesta clínica, ou o e-mail é de um login que
     *    não é médico (secretária etc.) → o mesmo erro de "e-mail em uso" de
     *    sempre (field = campo que casou) — nada revela quem é staff;
     *  - conflict: e-mail e CPF de pessoas diferentes (ou login excluído).
     *
     * @return array{status: string, user: ?User, field: ?string}
     */
    public function detect(?string $email, ?string $cpf, string $entityId): array
    {
        $byEmail = filled($email)
            ? User::query()->withTrashed()->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $email))])->first()
            : null;

        $cpfUserIds = filled($cpf)
            ? Doctor::query()
                ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
                ->join('people', 'people.id', '=', 'doctors.person_id')
                ->whereNull('entity_users.deleted_at')
                ->where('people.national_registry', $cpf)
                ->distinct()
                ->pluck('entity_users.user_id')
            : collect();

        // merge() devolve coleção nova — push() mutaria $cpfUserIds, usado
        // abaixo para saber se o CPF casou com algum médico.
        $userIds = $cpfUserIds
            ->merge($byEmail ? [$byEmail->id] : [])
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values();

        if ($userIds->isEmpty()) {
            return ['status' => self::DETECT_NONE, 'user' => null, 'field' => null];
        }

        $user = $byEmail ?? User::query()->withTrashed()->find($userIds->first());

        if ($userIds->count() > 1 || $user === null || $user->trashed()) {
            return ['status' => self::DETECT_CONFLICT, 'user' => null, 'field' => null];
        }

        if ($this->isMember($user, $entityId)) {
            return ['status' => self::DETECT_MEMBER, 'user' => $user, 'field' => $byEmail ? 'email' : 'national_registry'];
        }

        // O aviso de convite é só para quem É médico: e-mail de login de outro
        // perfil continua "e-mail em uso" (não confirma quem é staff).
        if ($byEmail !== null && $cpfUserIds->isEmpty() && ! $this->isDoctorLogin($user)) {
            return ['status' => self::DETECT_TAKEN, 'user' => null, 'field' => 'email'];
        }

        return ['status' => self::DETECT_INVITE, 'user' => $user, 'field' => null];
    }

    /** A clínica ainda tem vaga de médico no plano? */
    public function hasDoctorSlot(string $entityId): bool
    {
        return $this->featureGate->status($entityId, FeatureKey::MaxDoctors)->allowed;
    }

    /**
     * Cria (ou renova, se já houver um pendente) o convite e avisa o médico no
     * e-mail do login dele.
     *
     * @param array<string, mixed> $payload dados digitados pela clínica
     */
    public function invite(string $entityId, User $user, array $payload, ?string $invitedBy): DoctorInvitation
    {
        [$invitation, $notify] = DB::transaction(function () use ($entityId, $user, $payload, $invitedBy): array {
            $invitation = DoctorInvitation::query()
                ->where('entity_id', $entityId)
                ->where('user_id', $user->id)
                ->where('status', DoctorInvitation::STATUS_PENDING)
                ->lockForUpdate()
                ->first() ?? new DoctorInvitation(['entity_id' => $entityId, 'user_id' => $user->id]);

            $invitation->fill([
                'invited_by' => $invitedBy,
                'payload'    => $payload,
                'status'     => DoctorInvitation::STATUS_PENDING,
                'expires_at' => now()->addDays(DoctorInvitation::VALID_DAYS),
            ]);

            // Reenvio atualiza os dados, mas só manda outro e-mail depois do
            // intervalo — a clínica não usa o convite para lotar a caixa de
            // ninguém.
            $notify = $invitation->notified_at === null
                || $invitation->notified_at->lte(now()->subHours(DoctorInvitation::RESEND_COOLDOWN_HOURS));

            if ($notify) {
                $invitation->notified_at = now();
            }

            $invitation->save();

            return [$invitation, $notify];
        });

        if ($notify) {
            $user->notify(new DoctorClinicInvitation($invitation));
        }

        return $invitation;
    }

    /**
     * Aceite pelo PRÓPRIO médico (o controller já garantiu user === convidado).
     * Lock no convite: dois cliques/abas não criam dois vínculos.
     */
    public function accept(DoctorInvitation $invitation, User $user): string
    {
        return DB::transaction(function () use ($invitation, $user): string {
            $locked = DoctorInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked || (string) $locked->user_id !== (string) $user->id || ! $locked->isOpen()) {
                return self::ACCEPT_CLOSED;
            }

            $entityId = (string) $locked->entity_id;

            if (! Entity::query()->whereKey($entityId)->exists()) {
                return self::ACCEPT_CLOSED;
            }

            // Vaga no plano e unicidade na clínica conferidas em fila: dois
            // aceites simultâneos (convites diferentes) não estouram o limite.
            $this->lockClinicDoctors($entityId);

            if ($this->isMember($user, $entityId)) {
                $this->close($locked, DoctorInvitation::STATUS_ACCEPTED);

                return self::ACCEPT_MEMBER;
            }

            if (! $this->hasDoctorSlot($entityId)) {
                return self::ACCEPT_PLAN_LIMIT;
            }

            // Desde o convite, outro médico da clínica pode ter passado a usar
            // o mesmo CPF, CRM, especialidade ou cor: não reaproveita nem
            // duplica — a clínica envia um convite novo com dados corretos.
            if ($this->clashesWithClinicDoctors((array) $locked->payload, $entityId)) {
                return self::ACCEPT_CONFLICT;
            }

            $this->doctorService->attachExistingUser($user, $entityId, (array) $locked->payload, $locked->invited_by);
            $this->close($locked, DoctorInvitation::STATUS_ACCEPTED);

            return self::ACCEPT_OK;
        });
    }

    public function decline(DoctorInvitation $invitation, User $user): bool
    {
        return DB::transaction(function () use ($invitation, $user): bool {
            $locked = DoctorInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked || (string) $locked->user_id !== (string) $user->id || ! $locked->isOpen()) {
                return false;
            }

            $this->close($locked, DoctorInvitation::STATUS_DECLINED);

            return true;
        });
    }

    /** Cancelamento pela clínica dona do convite. */
    public function cancel(DoctorInvitation $invitation, string $entityId): bool
    {
        return DB::transaction(function () use ($invitation, $entityId): bool {
            $locked = DoctorInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (! $locked || (string) $locked->entity_id !== $entityId || $locked->status !== DoctorInvitation::STATUS_PENDING) {
                return false;
            }

            $this->close($locked, DoctorInvitation::STATUS_CANCELLED);

            return true;
        });
    }

    /**
     * Convites vencidos sem resposta: marca como expirados e descarta os dados
     * digitados (agendado diariamente — clinic-invitations:expire).
     */
    public function expireOverdue(): int
    {
        $expired = 0;

        DoctorInvitation::query()
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

    /** Retenção (LGPD): convites encerrados há mais de RETENTION_DAYS são apagados. */
    public function purgeClosed(): int
    {
        return DoctorInvitation::query()
            ->where('status', '!=', DoctorInvitation::STATUS_PENDING)
            ->where('updated_at', '<', now()->subDays(DoctorInvitation::RETENTION_DAYS))
            ->delete();
    }

    /** @param array<string, mixed> $payload */
    private function clashesWithClinicDoctors(array $payload, string $entityId): bool
    {
        $values = array_filter([
            'people.national_registry' => $payload['national_registry'] ?? null,
            'doctors.record'           => $payload['record'] ?? null,
            'doctors.record_specialty' => $payload['record_specialty'] ?? null,
            'doctors.color'            => $payload['color'] ?? null,
        ], fn ($value) => filled($value));

        if ($values === []) {
            return false;
        }

        return Doctor::query()
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->join('people', 'people.id', '=', 'doctors.person_id')
            ->where('entity_users.entity_id', $entityId)
            ->whereNull('entity_users.deleted_at')
            ->where(function ($query) use ($values): void {
                foreach ($values as $column => $value) {
                    $query->orWhere($column, $value);
                }
            })
            ->exists();
    }

    /** PostgreSQL: advisory lock de transação por clínica (vagas de médico). */
    private function lockClinicDoctors(string $entityId): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::select('select pg_advisory_xact_lock(?)', [
            (int) hexdec(substr(sha1('doctor-invitation-accept|' . $entityId), 0, 15)),
        ]);
    }

    /** O login já é (ou foi) médico em alguma clínica? */
    private function isDoctorLogin(User $user): bool
    {
        return Doctor::query()
            ->withTrashed()
            ->join('entity_users', 'entity_users.id', '=', 'doctors.entity_user_id')
            ->where('entity_users.user_id', $user->id)
            ->exists();
    }

    /** Fecha o convite e descarta os dados digitados (minimização — LGPD). */
    private function close(DoctorInvitation $invitation, string $status): void
    {
        $invitation->forceFill([
            'status'       => $status,
            'responded_at' => now(),
            'payload'      => null,
        ])->save();
    }

    private function isMember(User $user, string $entityId): bool
    {
        return EntityUser::query()
            ->where('user_id', $user->id)
            ->where('entity_id', $entityId)
            ->exists();
    }
}
