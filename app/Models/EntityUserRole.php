<?php

namespace App\Models;

use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model explícito para `entity_user_role` (EntityUser <-> Role).
 *
 * Necessário (em vez do pivot array default do BelongsToMany) porque a
 * tabela tem `id` uuid como chave primária própria — sem um Pivot model com
 * HasUuids, `attach()`/`sync()` inseririam a linha com `id` NULL e violariam
 * a constraint NOT NULL. Ligado via `->using(EntityUserRole::class)` nas
 * relations EntityUser::roles() e Role::entityUsers().
 *
 * `created_by` é preenchido automaticamente (mesma fonte que HasAuditColumns
 * usa — App\Support\AuditContext), mas sem a trait completa: esta tabela só
 * tem `created_by`, não `updated_by`/`deleted_by`/soft delete.
 */
class EntityUserRole extends Pivot
{
    use HasUuids;

    protected $table = 'entity_user_role';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'entity_user_id',
        'role_id',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            if (blank($pivot->created_by)) {
                $pivot->created_by = AuditContext::userId();
            }
        });
    }
}
