<?php

namespace Database\Seeders;

use App\Models\{Entity, EntityUser, User};
use App\Support\{AuditContext, SeedCredentials};
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Empresa do SaaS (Medical Group) e seus administradores.
 *
 * Idempotente: rodar `db:seed` num banco que já tem os dados não duplica
 * nem aborta (firstOrCreate por e-mail/subdomínio).
 *
 * Senhas (decisão do dono — App\Support\SeedCredentials):
 *  - modo fixo (APP_ENV=local ou SEED_FIXED_CREDENTIALS=true — testes
 *    automatizados): o usuário NOVO nasce com a senha fixa do repositório;
 *  - homologação e produção: o usuário NOVO nasce com senha aleatória,
 *    mostrada uma vez no terminal.
 * Usuário que JÁ existe nunca tem a senha trocada (é o admin real do SaaS):
 * fora do modo fixo, se ele ainda usa a senha do repositório, o seeder só
 * avisa no terminal para trocar.
 */
class EntityAndUserAdministratorSeeder extends Seeder
{
    /** @var list<array{email: string, name: string, password: string}> */
    private const ADMINS = [
        ['email' => 'higor_ap89@icloud.com', 'name' => 'Higor', 'password' => 'Admin@2024!'],
        ['email' => 'joao9@adachioftalmologia.com.br', 'name' => 'João Adachi', 'password' => 'AX9ser4D%K'],
    ];

    public function run(): void
    {
        $admins = [];

        foreach (self::ADMINS as $admin) {
            $admins[] = $this->ensureAdmin($admin);

            // Define o user_id global para o trait HasAuditColumns (auth()->id()
            // é null no console/seeder) a partir do primeiro admin, para os
            // registros criados na sequência.
            if (count($admins) === 1) {
                AuditContext::setUserId($admins[0]->id);

                if ($admins[0]->created_by === null) {
                    $admins[0]->created_by = $admins[0]->id;
                    $admins[0]->saveQuietly();
                }
            }
        }

        $entity = Entity::firstOrCreate(['subdomain' => 'medicalgroup'], [
            'name'                   => 'Medical Group',
            'zipcode'                => '09015620',
            'address'                => 'Rua Tatuí',
            'number'                 => '507',
            'complement'             => 'Apto 82',
            'district'               => 'Casa Branca',
            'city'                   => 'Santo André',
            'state'                  => 'SP',
            'country'                => 'BR',
            'national_registration'  => '01234567890123',
            'state_registration'     => '4567890123456',
            'municipal_registration' => '78901234567890',
            'telephone'              => '1140028922',
            'cellphone'              => '11999999999',
            'email'                  => 'contato@medicalgroup.com',
            'website'                => 'medicalgroup.com',
            'logo'                   => null,
            'is_client'              => false,
            'active'                 => true,
        ]);

        foreach ($admins as $admin) {
            EntityUser::firstOrCreate(
                ['entity_id' => $entity->id, 'user_id' => $admin->id],
                ['active' => true, 'rule' => 'admin'],
            );
        }
    }

    /** @param array{email: string, name: string, password: string} $admin */
    private function ensureAdmin(array $admin): User
    {
        $user = User::firstWhere('email', $admin['email']);

        if ($user) {
            if (! SeedCredentials::fixed() && Hash::check($admin['password'], (string) $user->password)) {
                $this->command?->warn("  ⚠ {$admin['email']} ainda usa a senha do repositório — troque pelo painel (\"Minha conta\") ou \"Esqueci a senha\". O seeder não troca a senha de admin existente.");
            }

            return $user;
        }

        $password = SeedCredentials::fixed() ? $admin['password'] : SeedCredentials::generate();

        $user = User::create([
            'name'              => $admin['name'],
            'email'             => $admin['email'],
            'email_verified_at' => Carbon::now(),
            'password'          => $password, // cast 'hashed' faz o hash
            'remember_token'    => Str::random(10),
        ]);

        if (! SeedCredentials::fixed()) {
            $this->command?->warn('  Senha gerada AGORA para admin do SaaS — anote; não será mostrada de novo (fica no histórico do terminal/log de CI):');
            $this->command?->getOutput()->writeln("  admin · {$admin['email']} · {$password}", OutputInterface::OUTPUT_RAW);
        }

        return $user;
    }
}
