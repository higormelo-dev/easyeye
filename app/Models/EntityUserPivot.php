<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Concerns\AsPivot;

/**
 * Pivot de `entity_users` para os BelongsToMany Entity::users() e
 * User::entities(). A tabela tem `id` uuid próprio e `code` gerado no
 * booted() de EntityUser: com o pivot padrão, attach() inseria `id` NULL.
 * Herda de EntityUser (HasUuids, código, auditoria) — mesmo tratamento de
 * ScheduleResource. Auditoria continua com o tipo EntityUser.
 */
class EntityUserPivot extends EntityUser
{
    use AsPivot;

    protected $table = 'entity_users';

    public $incrementing = false;

    protected $keyType = 'string';

    public function getMorphClass(): string
    {
        return EntityUser::class;
    }
}
