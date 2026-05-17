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

            return $this->findOrCreateEntityUser($user);
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
            if ($existingUser->trashed()) {
                $existingUser->restore();
            }

            $existingUser->update([
                'name'     => $request->name,
                'password' => $request->password,
            ]);
            $existingUser->markEmailAsVerified();

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
    private function findOrCreateEntityUser(User $user): EntityUser
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
                'rule'   => 'doctor',
                'active' => true,
            ]);

            return $existingRecord;
        }

        return EntityUser::create([
            'entity_id' => session()->get('selected_entity_id'),
            'user_id'   => $user->id,
            'rule'      => 'doctor',
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
