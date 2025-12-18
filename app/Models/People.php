<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\{Model, SoftDeletes};
use Illuminate\Notifications\Notifiable;

class People extends Model
{
    use HasFactory;
    use Notifiable;
    use HasUuids;
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
}
