<?php

namespace App\Actions\Register;

use App\Models\{Entity, EntityUser, User};

class CreateEntityUserAction
{
    public function execute(User $user, Entity $entity): EntityUser
    {
        return EntityUser::create([
            'entity_id' => $entity->id,
            'user_id'   => $user->id,
            'rule'      => 'admin',
            'is_owner'  => true,
            'active'    => true,
            'joined_at' => now(),
        ]);
    }
}
