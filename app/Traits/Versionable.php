<?php

declare(strict_types = 1);

namespace App\Traits;

use App\Models\RecordVersion;
use App\Services\VersionService;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Cria um snapshot completo do registro antes de cada atualização.
 * Aplicar apenas em modules clínicos críticos: MedicalRecord, Report.
 *
 * Configure campos a excluir do snapshot declarando no model:
 *   protected array $versionExclude = ['internal_field'];
 */
trait Versionable
{
    public static function bootVersionable(): void
    {
        static::updating(function (self $model): void {
            app(VersionService::class)->snapshot($model);
        });
    }

    public function versions(): MorphMany
    {
        return $this->morphMany(RecordVersion::class, 'versionable')
            ->orderBy('version', 'desc');
    }

    /**
     * Campos a excluir do snapshot neste model específico.
     */
    public function getVersionExclude(): array
    {
        return $this->versionExclude ?? [];
    }
}
