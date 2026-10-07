<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot explícito de `schedule_resources` (agendamento ↔ sala/equipamento).
 *
 * A tabela tem `id` uuid como chave primária própria: sem um Pivot com
 * HasUuids, `attach()`/`sync()` inseriam a linha com `id` NULL e estouravam
 * NOT NULL (500) — reservar sala/equipamento na Agenda nunca gravava. Mesmo
 * padrão de EntityUserRole. Ligado via `->using()` em Schedule::resources() e
 * ClinicResource::schedules().
 */
class ScheduleResource extends Pivot
{
    use HasUuids;

    protected $table = 'schedule_resources';

    public $incrementing = false;

    protected $keyType = 'string';
}
