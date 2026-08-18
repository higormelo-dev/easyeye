<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Models\PermissionRecord;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Popula o catálogo global `permissions` a partir dos cases de
 * App\Enums\Permission — fonte única de verdade de key/label/group.
 *
 * Idempotente: firstOrCreate por `key`. Rodar novamente após adicionar um
 * case novo ao enum sincroniza o catálogo sem duplicar nem afetar registros
 * já vinculados a Role via role_permission.
 */
class PermissionsSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (Permission::cases() as $case) {
            PermissionRecord::query()->firstOrCreate(
                ['key' => $case->value],
                [
                    'label' => $case->label(),
                    'group' => $case->group(),
                ],
            );
        }
    }
}
