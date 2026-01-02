<?php

namespace App\Models;

use App\Presenters\PeoplePresenter;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Notifications\Notifiable;
use Laracasts\Presenter\PresentableTrait;

class People extends Model
{
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use PresentableTrait;
    use SoftDeletes;

    protected $primaryKey = 'id';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'full_name',
        'nickname',
        'birth_date',
        'gender',
        'marital_status',
        'email',
        'mother_name',
        'father_name',
        'national_registry',
        'state_registry',
        'state_registry_agency',
        'state_registry_initial',
        'state_registry_date',
        'telephone',
        'cellphone',
        'whatsapp',
        'zipcode',
        'address',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'country',
        'photo',
    ];

    protected $presenter = PeoplePresenter::class;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date'          => 'date',
            'state_registry_date' => 'date',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime',
            'deleted_at'          => 'datetime',
        ];
    }

    /**
     * Gender of people
     *
     * 1 => 'MASCULINO',
     * 2 => 'FEMININO',
     */
    public static array $genders = [
        1 => 'MASCULINO',
        2 => 'FEMININO',
    ];

    /**
     * Merital status of people
     *
     * 1 => 'Solteiro(a)',
     * 2 => 'Casado(a)',
     * 3 => 'Divorciado(a)',
     * 4 => 'Viúvo(a)',
     * 5 => 'União Estável',
     * 6 => 'Separado(a) Judicialmente',
     * 7 => 'Marital',
     * 8 => 'Outro',
     */
    public static array $maritalStatuses = [
        1 => 'Solteiro(a)',
        2 => 'Casado(a)',
        3 => 'Divorciado(a)',
        4 => 'Viúvo(a)',
        5 => 'União Estável',
        6 => 'Separado(a) Judicialmente',
        7 => 'Marital',
        8 => 'Outro',
    ];

    /**
     * States of Brazil
     *
     * 1 => 'Solteiro(a)',
     * 2 => 'Casado(a)',
     * 3 => 'Divorciado(a)',
     * 4 => 'Viúvo(a)',
     * 5 => 'União Estável',
     * 6 => 'Separado(a) Judicialmente',
     * 7 => 'Marital',
     * 8 => 'Outro',
     */
    public static array $statesOfBrazil = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    ];
}
