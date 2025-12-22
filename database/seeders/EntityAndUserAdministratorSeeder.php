<?php

namespace Database\Seeders;

use App\Models\{Entity, EntityUser, User};
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class EntityAndUserAdministratorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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
            'country'                => 'Brasil',
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
        $higor = User::create([
            'name'              => 'Higor',
            'email'             => 'higor_ap89@icloud.com',
            'email_verified_at' => Carbon::now(),
            'password'          => Hash::make('$2y$10$92IX'),
            'remember_token'    => Str::random(10),
        ]);
        EntityUser::create([
            'entity_id' => $entity->id,
            'user_id'   => $higor->id,
            'active'    => true,
            'rule'      => 'admin',
        ]);
    }
}
