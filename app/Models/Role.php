<?php

namespace App\Models;

use App\Traits\{Auditable, HasAuditColumns};
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Database\Eloquent\Relations\{BelongsTo, BelongsToMany};

/**
 * Perfil customizado de acesso (RBAC granular ADITIVO), sempre escopado por
 * entity_id — cada clínica mantém seus próprios perfis, compostos por um
 * conjunto de permissions() (App\Models\PermissionRecord).
 *
 * NUNCA usar Role/permissions() para conceder ações clínicas/regulatórias
 * travadas (assinar laudo, prescrever, etc.) — essas continuam hardcoded via
 * ClientRule::Doctor direto nos Gates. Ver App\Enums\Permission para a
 * regra de compliance completa.
 */
class Role extends Model
{
    use Auditable;
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'entity_id',
        'name',
        'description',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class, 'entity_id', 'id');
    }

    /**
     * Permissions atribuídas a este perfil.
     * `Permission` aqui é o model Eloquent App\Models\PermissionRecord
     * (tabela `permissions`) — não confundir com o enum App\Enums\Permission.
     *
     * Chaves de pivot explícitas: a convenção padrão do Eloquent inferiria
     * `permission_record_id` a partir do nome da classe PermissionRecord,
     * mas a coluna real na tabela pivot é `permission_id` (nome baseado na
     * tabela `permissions`, não no model).
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(PermissionRecord::class, 'role_permission', 'role_id', 'permission_id');
    }

    /**
     * EntityUsers (memberships) que possuem este perfil atribuído.
     */
    public function entityUsers(): BelongsToMany
    {
        return $this->belongsToMany(EntityUser::class, 'entity_user_role')
            ->using(EntityUserRole::class)
            ->withTimestamps();
    }
}
