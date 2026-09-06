<?php

namespace App\Services;

use App\Http\Requests\EntityUserRequest;
use App\Models\{EntityUser, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                $this->updateUser($entityUser->user, $request);
            }

            return $entityUser;
        });
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

    /**
     * Update user data.
     */
    private function updateUser(User $user, Request $request): void
    {
        $user->update([
            'name'  => $request->name,
            'email' => $request->email,
        ]);
    }
}
