<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\{Entity, EntityUser, People, User};
use Database\Seeders\DataFakersSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Hash};

/**
 * Backfill leve e idempotente do usuário 'financial' nas clínicas de teste,
 * sem rodar o DataFakersSeeder inteiro (que gera empresas fake novas a cada
 * execução — não é seguro repetir em staging/produção a cada deploy).
 *
 * Motivo de existir: o pipeline de deploy roda `migrate --force` mas não
 * roda seeders (por padrão, e com razão — DataFakersSeeder não é idempotente
 * na parte de geração de empresas). Isso deixa ambientes cujo banco foi
 * seedado ANTES da correção em DataFakersSeeder (commit 37a4080) sem nenhum
 * usuário com perfil Financeiro pra QA testar — mesmo com o código já certo.
 *
 * Uso:
 *   php artisan clinics:ensure-financial-profiles            # dry-run, lista o que faria
 *   php artisan clinics:ensure-financial-profiles --force    # aplica
 *
 * Seguro rodar repetidas vezes (inclusive num hook de deploy "Post"): cada
 * clínica só é tocada se ainda não tiver nenhum entity_user com rule=financial.
 */
class EnsureTestFinancialProfilesCommand extends Command
{
    protected $signature = 'clinics:ensure-financial-profiles
                            {--force : aplica as alterações (sem isso é dry-run)}';

    protected $description = 'Garante 1 usuário com perfil Financeiro por clínica (e o financeiro@clinicateste.com fixo) sem rodar o seeder inteiro.';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $entitiesMissingFinancial = Entity::query()
            ->where('code', '!=', 'ENT-0000000001') // entidade reservada do SaaS, nunca é clínica cliente
            ->whereDoesntHave('entityUsers', fn ($q) => $q->where('rule', 'financial'))
            ->whereHas('entityUsers')
            ->get(['id', 'code', 'name']);

        if ($entitiesMissingFinancial->isEmpty()) {
            $this->info('Nenhuma clínica sem perfil Financeiro. Nada a fazer.');
        } else {
            $this->warn(sprintf('%d clínica(s) sem nenhum usuário Financeiro:', $entitiesMissingFinancial->count()));

            foreach ($entitiesMissingFinancial as $entity) {
                $this->line("  • {$entity->code} — {$entity->name}");
            }
        }

        $testEntity               = Entity::where('subdomain', DataFakersSeeder::INTEGRATOR_TEST_ENTITY_SUBDOMAIN)->first();
        $testEntityNeedsFixedUser = $testEntity
            && ! EntityUser::query()
                ->where('entity_id', $testEntity->id)
                ->where('rule', 'financial')
                ->exists();

        if ($testEntityNeedsFixedUser) {
            $this->warn("  • Clínica Teste Integrador ({$testEntity->code}) também sem o financeiro@clinicateste.com fixo.");
        }

        if ($entitiesMissingFinancial->isEmpty() && ! $testEntityNeedsFixedUser) {
            return self::SUCCESS;
        }

        if (! $force) {
            $this->newLine();
            $this->comment('Dry-run. Rode novamente com --force para aplicar.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($entitiesMissingFinancial, $testEntity, $testEntityNeedsFixedUser): void {
            foreach ($entitiesMissingFinancial as $entity) {
                // Mesmo critério do DataFakersSeeder: promove um entity_user
                // existente que não seja admin/doctor, em vez de criar gente nova.
                $candidate = EntityUser::query()
                    ->where('entity_id', $entity->id)
                    ->whereNotIn('rule', ['admin', 'doctor'])
                    ->inRandomOrder()
                    ->first();

                if (! $candidate) {
                    $this->line("  ⚠ {$entity->code}: só tem admin/doctor, nenhum candidato pra promover — pulado.");

                    continue;
                }

                $candidate->update(['rule' => 'financial']);
                $this->line("  ✓ {$entity->code}: {$candidate->code} promovido a Financeiro.");
            }

            if ($testEntityNeedsFixedUser) {
                $person = People::updateOrCreate(
                    ['email' => 'financeiro@clinicateste.com'],
                    ['full_name' => 'FINANCEIRO CLÍNICA TESTE', 'cellphone' => ''],
                );
                $user = User::updateOrCreate(
                    ['email' => 'financeiro@clinicateste.com'],
                    [
                        'name'              => $person->full_name,
                        'email_verified_at' => now(),
                        'password'          => Hash::make('Financeiro@123'),
                    ],
                );
                EntityUser::updateOrCreate(
                    ['entity_id' => $testEntity->id, 'user_id' => $user->id],
                    ['rule' => 'financial', 'active' => true],
                );

                $this->line('  ✓ financeiro@clinicateste.com / Financeiro@123 garantido na Clínica Teste Integrador.');
            }
        });

        $this->info('Concluído.');

        return self::SUCCESS;
    }
}
