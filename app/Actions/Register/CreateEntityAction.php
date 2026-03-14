<?php

namespace App\Actions\Register;

use App\Models\Entity;
use Illuminate\Support\Str;

class CreateEntityAction
{
    public function execute(array $data): Entity
    {
        /*
         * withoutObservers() suprime o EntityObserver::created() que criaria
         * um trial automático. O StartTrialAction cuidará disso com o plano
         * escolhido pelo usuário no wizard.
         */
        return Entity::withoutObservers(function () use ($data) {
            return Entity::create([
                'name'                  => $data['company_name'],
                'subdomain'             => $this->generateSubdomain($data['company_name']),
                'national_registration' => $data['company_cnpj'] ?? null,
                'email'                 => $data['email'],
                'is_client'             => true,
                'active'                => true,
            ]);
        });
    }

    private function generateSubdomain(string $companyName): string
    {
        $base = Str::slug($companyName);

        if ($base === '') {
            $base = 'empresa';
        }

        // Limita a 50 caracteres para não exceder índices do banco
        $base = Str::substr($base, 0, 50);

        $subdomain = $base;
        $counter   = 1;

        while (Entity::withTrashed()->where('subdomain', $subdomain)->exists()) {
            $subdomain = $base . '-' . (++$counter);
        }

        return $subdomain;
    }
}
