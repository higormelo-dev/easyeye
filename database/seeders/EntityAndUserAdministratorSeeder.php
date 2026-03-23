<?php

namespace Database\Seeders;

use App\Models\{Entity, EntityUser, User};
use App\Support\AuditContext;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
// Hash não é necessário: o cast 'hashed' do model User já aplica Hash::make() automaticamente
use Illuminate\Support\Str;

class EntityAndUserAdministratorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Cria o $higor primeiro e faz login para que auth()->id()
        // esteja disponível nos traits HasAuditColumns e Auditable
        // em todos os registros criados na sequência.
        $higor = User::create([
            'name'              => 'Higor',
            'email'             => 'higor_ap89@icloud.com',
            'email_verified_at' => Carbon::now(),
            'password'          => 'Admin@2024!',
            'remember_token'    => Str::random(10),
        ]);

        // Define o user_id global para o trait HasAuditColumns,
        // pois auth()->id() é null em contexto de console/seeder.
        AuditContext::setUserId($higor->id);

        // Retroage o vínculo no próprio $higor, criado antes do forceUserId
        $higor->created_by = $higor->id;
        $higor->saveQuietly();

        $entity = Entity::create([
            'name'                   => 'Medical Group',
            'subdomain'              => 'medicalgroup',
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
        $joao = User::create([
            'name'              => 'João Adachi',
            'email'             => 'joao9@adachioftalmologia.com.br',
            'email_verified_at' => Carbon::now(),
            'password'          => 'AX9ser4D%K',    // cast 'hashed' faz o hash automaticamente
            'remember_token'    => Str::random(10),
        ]);
        EntityUser::create([
            'entity_id' => $entity->id,
            'user_id'   => $higor->id,
            'active'    => true,
            'rule'      => 'admin',
        ]);

        EntityUser::create([
            'entity_id' => $entity->id,
            'user_id'   => $joao->id,
            'active'    => true,
            'rule'      => 'admin',
        ]);
    }
}
