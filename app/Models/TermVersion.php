<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class TermVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'version',
        'content',
        'summary',
        'effective_from',
        'active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'active'         => 'boolean',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(UserTermAcceptance::class, 'term_version_id');
    }

    /**
     * Retorna a versão ativa mais recente de um tipo de documento.
     */
    public static function currentFor(string $type): ?self
    {
        return static::query()
            ->where('type', $type)
            ->where('active', true)
            ->where('effective_from', '<=', now()->toDateString())
            ->orderBy('effective_from', 'desc')
            ->orderBy('version', 'desc')
            ->first();
    }
}
