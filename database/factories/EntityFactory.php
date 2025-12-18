<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Entity>
 */
class EntityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name'                   => $name,
            'subdomain'              => Str::slug($name),
            'zipcode'                => str(fake()->postcode())->replaceMatches('/\D/', ''),
            'address'                => fake()->streetName(),
            'number'                 => fake()->buildingNumber(),
            'complement'             => fake()->secondaryAddress(),
            'district'               => fake()->cityPrefix() . ' ' . fake()->citySuffix(),
            'city'                   => fake()->city(),
            'state'                  => fake()->stateAbbr(),
            'country'                => fake()->country(),
            'national_registration'  => str(fake()->cnpj())->replaceMatches('/\D/', ''),
            'state_registration'     => fake()->numerify('###########'),
            'municipal_registration' => fake()->numerify('#########'),
            'telephone'              => str(fake()->phoneNumber())->replaceMatches('/\D/', ''),
            'cellphone'              => str(fake()->cellphoneNumber())->replaceMatches('/\D/', ''),
            'email'                  => fake()->companyEmail(),
            'website'                => fake()->domainName(),
            'logo'                   => null,
            'is_client'              => true,
            'active'                 => true,
        ];
    }
}
