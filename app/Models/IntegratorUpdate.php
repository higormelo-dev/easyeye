<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class IntegratorUpdate extends Model
{
    use HasUuids;

    protected $fillable = [
        'version',
        'platform',
        'arch',
        'archive',
        'sha256',
        'signature',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
