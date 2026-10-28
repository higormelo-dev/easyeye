<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SystemProfile;
use Illuminate\Database\Seeder;

/**
 * Sincroniza o catálogo de perfis FIXOS da plataforma (system_profiles) a
 * partir de SystemProfile::CATALOG — fonte única de labels/descrições.
 *
 * Idempotente por (context, key): nunca sobrescreve personalizações feitas
 * pelo dono do SaaS. Ambientes já provisionados recebem as linhas pela
 * migration create_system_profiles_table (mesmo padrão do catálogo de
 * permissions).
 */
class SystemProfilesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (SystemProfile::CATALOG as $context => $profiles) {
            $sort = 0;

            foreach ($profiles as $key => $row) {
                $sort++;

                SystemProfile::query()->firstOrCreate(
                    ['context' => $context, 'key' => $key],
                    [
                        'label'       => $row['label'],
                        'description' => $row['description'],
                        'sort_order'  => $sort,
                    ],
                );
            }
        }
    }
}
