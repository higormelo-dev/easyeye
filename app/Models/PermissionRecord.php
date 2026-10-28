<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Registro Eloquent do catálogo global da tabela `permissions`.
 *
 * NOME: chamado de PermissionRecord (e não `Permission`) de propósito, para
 * não colidir com o enum App\Enums\Permission — que é a fonte única de
 * verdade de key/label/group e o tipo usado em toda a lógica de ACL
 * (hasPermissionInEntity(), middleware `permission:`). Este model é só a
 * representação de persistência da tabela, usada nas relações Eloquent
 * (Role::permissions()) e no PermissionsSeeder.
 *
 * Catálogo fixo: sem entity_id (global, não por clínica), sem soft delete,
 * mantido apenas por PermissionsSeeder — não editável via UI/admin.
 */
class PermissionRecord extends Model
{
    use HasUuids;

    protected $table = 'permissions';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'label',
        'group',
        'description',
    ];

    /**
     * Chave de pivot explícita: a convenção padrão do Eloquent inferiria
     * `permission_record_id` a partir do nome desta classe (PermissionRecord),
     * mas a coluna real na tabela pivot é `permission_id`.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission', 'permission_id', 'role_id');
    }
}
