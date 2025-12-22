<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\People>
 */
class PeopleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $gender   = fake()->randomElement([1, 0]);
        $fullName = fake()->name($gender === 1 ? 'male' : 'female');

        $nameParts      = explode(' ', $fullName);
        $titlesToIgnore = ['Dr.', 'Dra.', 'Sr.', 'Sra.', 'Prof.', 'Profa.', 'Mr.', 'Mrs.', 'Ms.'];

        $firstName = $nameParts[0];

        foreach ($nameParts as $part) {
            if (!in_array($part, $titlesToIgnore)) {
                $firstName = $part;

                break;
            }
        }

        $nickname = mb_convert_case($firstName, MB_CASE_TITLE, 'UTF-8');

        return [
            'full_name'              => $fullName,
            'nickname'               => $nickname,
            'birth_date'             => fake()->dateTimeBetween('-80 years', '-18 years')->format('Y-m-d'),
            'gender'                 => $gender,
            'marital_status'         => fake()->randomElement([1, 2, 3, 4, 5]),
            'email'                  => fake()->unique()->safeEmail(),
            'mother_name'            => fake()->name('female'),
            'father_name'            => fake()->name('male'),
            'national_registry'      => str(fake()->cpf())->replaceMatches('/\D/', ''),
            'state_registry'         => str(fake()->rg())->replaceMatches('/\D/', ''),
            'state_registry_agency'  => fake()->randomElement(['SSP', 'IFP', 'DETRAN', 'PC']),
            'state_registry_initial' => fake()->stateAbbr(),
            'state_registry_date'    => fake()->dateTimeBetween('-30 years', '-18 years')->format('Y-m-d'),
            'telephone'              => str(fake()->phoneNumber())->replaceMatches('/\D/', ''),
            'cellphone'              => str(fake()->cellphoneNumber())->replaceMatches('/\D/', ''),
            'whatsapp'               => fake()->randomElement([true, false]),
            'zipcode'                => str(fake()->postcode())->replaceMatches('/\D/', ''),
            'address'                => fake()->streetName(),
            'number'                 => fake()->buildingNumber(),
            'complement'             => fake()->optional(0.3)->secondaryAddress(),
            'district'               => fake()->cityPrefix() . ' ' . fake()->citySuffix(),
            'city'                   => fake()->city(),
            'state'                  => fake()->stateAbbr(),
            'country'                => 'Brasil',
            'photo'                  => fake()->optional(0.4)->imageUrl(200, 200, 'people'),
            'latitude'               => fake()->latitude(-33.7683777, 5.2842873),
            'longitude'              => fake()->longitude(-73.9872354, -28.6341773),

        ];
    }
}
