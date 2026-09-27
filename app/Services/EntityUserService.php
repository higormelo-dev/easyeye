<?php

namespace App\Services;

use App\Http\Requests\EntityUserRequest;
use App\Models\{EntityUser, Partner, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EntityUserService
{
    /**
     * Create a new doctor with all related entities.
     */
    public function create(EntityUserRequest $request): EntityUser
    {
        return DB::transaction(function () use ($request) {
            $user = $this->findOrCreateUser($request);

            return $this->findOrCreateEntityUser($user, $request->rule);
        });
    }

    /**
     * Update existing doctor and related entities.
     */
    public function update(EntityUser $entityUser, EntityUserRequest $request): EntityUser
    {
        // Proprietário não pode ser desativado nem ter seu perfil alterado
        if ($entityUser->is_owner) {
            abort(403, trans('access_control.owner_protected'));
        }

        // Usuário não pode desativar a si mesmo
        if ($request->has('active') && ! $request->boolean('active') && $entityUser->user_id === auth()->id()) {
            abort(403, trans('access_control.self_protected'));
        }

        return DB::transaction(function () use ($entityUser, $request) {
            $data = [];

            if ($request->has('active')) {
                $data['active'] = $request->boolean('active');
            }

            if ($request->has('rule')) {
                $data['rule'] = $request->rule;
            }

            $entityUser->update($data);

            if (! $request->has('type_method')) {
                $this->updateLoginGuarded(
                    $entityUser->user,
                    (string) $request->name,
                    (string) $request->email,
                    (string) $entityUser->entity_id,
                );
            }

            return $entityUser;
        });
    }

    /**
     * O login (User) é GLOBAL. Além desta clínica, ele acessa outra (vínculo
     * em outra entity — inclusive removido, que pode ser restaurado) ou o
     * portal de parceiros?
     */
    public function userHasAccessOutsideEntity(User $user, string $entityId): bool
    {
        return EntityUser::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('entity_id', '!=', $entityId)
            ->exists()
            || Partner::query()->withTrashed()->where('user_id', $user->id)->exists();
    }

    /**
     * Grava nome/e-mail de login editados pela clínica.
     *
     * Antes qualquer admin reescrevia users.email de um login que também
     * acessa OUTRA clínica (ex.: vinculado pela importação de médicos) e,
     * com "esqueci minha senha" no e-mail novo, entrava como a vítima na
     * clínica dela. Login compartilhado só muda pelo próprio dono (Meu
     * perfil, que ainda exige reverificar o e-mail). Sem alteração real
     * (o formulário reenvia os campos), segue normalmente.
     *
     * @throws ValidationException
     */
    public function updateLoginGuarded(User $user, string $name, string $email, string $entityId): void
    {
        $user->fill(['name' => $name, 'email' => $email]);

        if (! $user->isDirty(['name', 'email'])) {
            return;
        }

        if ($this->userHasAccessOutsideEntity($user, $entityId)) {
            throw ValidationException::withMessages([
                'email' => __('shared_identity.user_shared_readonly'),
            ]);
        }

        $user->save();
    }

    /**
     * Find by ID or Code including soft-deleted records.
     */
    public function findByIdOrCode(string $idOrCode): ?EntityUser
    {
        /** @var EntityUser $record */
        $record = EntityUser::query()
            ->withTrashed()
            ->where('entity_id', session()->get('selected_entity_id'))
            ->when(
                Str::isUuid($idOrCode),
                static fn ($q) => $q->where('id', $idOrCode),
                static fn ($q) => $q->where('code', $idOrCode),
            )
            ->firstOrFail();

        return $record;
    }

    /**
     * Find or create user.
     */
    private function findOrCreateUser(EntityUserRequest $request): User
    {
        $existingUser = User::query()->withTrashed()
            ->where('email', $request->email)->first();

        if ($existingUser) {
            // BUGFIX (revisao de seguranca, hardening de follow-up ao achado
            // de account takeover no DoctorService): NUNCA sobrescrever nome/
            // senha/verificação de um User já existente encontrado por
            // e-mail — isso permitiria a um staff que soubesse o e-mail de
            // login de outra pessoa "roubar" a conta reescrevendo a senha via
            // este fluxo. Hoje EntityUserRequest já valida unicidade em
            // users.email antes de chegar aqui, então este ramo só é
            // alcançado legitimamente (e-mail duplicado é rejeitado na
            // validação) — isto é defesa em profundidade, não o fix
            // primário. Restaura se soft-deleted e reaproveita a identidade
            // como está, sem mutar credenciais.
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            return $existingUser;
        }

        /** @var User $user */
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password,
        ]);
        // $user->markEmailAsVerified();

        return $user;
    }

    /**
     * Find or create entity user.
     */
    private function findOrCreateEntityUser(User $user, string $rule): EntityUser
    {
        $existingRecord = EntityUser::query()
            ->withTrashed()
            ->where('user_id', $user->id)
            ->where('entity_id', session()->get('selected_entity_id'))
            ->first();

        if ($existingRecord) {
            if ($existingRecord->trashed()) {
                $existingRecord->restore();
            }

            $existingRecord->update([
                'rule'   => $rule,
                'active' => true,
            ]);

            return $existingRecord;
        }

        return EntityUser::create([
            'entity_id' => session()->get('selected_entity_id'),
            'user_id'   => $user->id,
            'rule'      => $rule,
            'active'    => true,
        ]);
    }
}
