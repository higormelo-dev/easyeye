<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Enums\MedicalSpecialty;
use App\Models\{ClinicResource, Doctor};
use App\Services\Dashboard\ClinicOperationsService;
use App\Support\ScheduleShift;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Filtros da Agenda lidos da URL (preserveState) — os mesmos para a lista,
 * o calendário (dia/semana/mês), os contadores e a impressão.
 *
 *  - Escopo de médico SEMPRE do servidor: perfil médico vê só o próprio
 *    (App\Support\CurrentDoctor); `doctor` da URL só vale para os demais.
 *  - `status`: chip de contador (ClinicOperationsService::DAY_COUNTERS) ou
 *    `blocked` (bloqueios e compromissos). Valor desconhecido = sem filtro.
 *  - `specialty` (MedicalSpecialty) e `resource` (sala/equipamento ATIVO da
 *    clínica) — inválido/de outra clínica = sem filtro.
 *  - `range`: day | week | month — período visível (semana começa no domingo,
 *    como o calendário lateral); intervalo [from, to).
 */
final class AgendaFilters
{
    public const STATUSES = [...ClinicOperationsService::DAY_COUNTERS, 'blocked'];

    public const RANGES = ['day', 'week', 'month'];

    public function __construct(
        public readonly string $entityId,
        public readonly Carbon $date,
        public readonly string $range,
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?string $doctorId,
        public readonly int $bout,
        public readonly ?string $status,
        public readonly ?int $specialty,
        public readonly ?string $resourceId,
        public readonly string $search,
    ) {
    }

    public static function fromRequest(Request $request, string $entityId, ?Doctor $loggedDoctor, ?string $forceRange = null): self
    {
        $date = rescue(
            fn () => Carbon::parse($request->filled('date') ? (string) $request->string('date') : now()->toDateString())->startOfDay(),
            fn () => now()->startOfDay(),
            report: false,
        );

        $range = $forceRange ?? (in_array($request->string('range')->value(), self::RANGES, true) ? $request->string('range')->value() : 'day');

        [$from, $to] = match ($range) {
            'week'  => [$date->copy()->startOfWeek(Carbon::SUNDAY), $date->copy()->startOfWeek(Carbon::SUNDAY)->addWeek()],
            'month' => [$date->copy()->startOfMonth(), $date->copy()->startOfMonth()->addMonth()],
            default => [$date->copy(), $date->copy()->addDay()],
        };

        $doctor = $request->string('doctor', 'tudo')->value();

        $status = $request->string('status')->value();
        $status = in_array($status, self::STATUSES, true) && $status !== 'total' ? $status : null;

        $specialty = MedicalSpecialty::tryFrom($request->integer('specialty'))?->value;

        $resource   = $request->string('resource')->value();
        $resourceId = Str::isUuid($resource) && ClinicResource::query()
            ->where('entity_id', $entityId)
            ->where('active', true)
            ->whereKey($resource)
            ->exists() ? $resource : null;

        return new self(
            entityId: $entityId,
            date: $date,
            range: $range,
            from: $from,
            to: $to,
            doctorId: $loggedDoctor ? (string) $loggedDoctor->id : (Str::isUuid($doctor) ? $doctor : null),
            bout: in_array($request->integer('bout', 1), [1, 2, 3, 4], true) ? $request->integer('bout', 1) : 1,
            status: $status,
            specialty: $specialty,
            resourceId: $resourceId,
            search: $request->string('search')->trim()->value(),
        );
    }

    public function shift(): ?string
    {
        return ScheduleShift::fromBout($this->bout);
    }

    /** Filtros devolvidos à página (a URL é a fonte da verdade). */
    public function toArray(): array
    {
        return [
            'date'      => $this->date->format('Y-m-d'),
            'range'     => $this->range,
            'from'      => $this->from->format('Y-m-d'),
            'to'        => $this->to->copy()->subDay()->format('Y-m-d'),
            'doctor'    => $this->doctorId ?? 'tudo',
            'bout'      => $this->bout,
            'status'    => $this->status,
            'specialty' => $this->specialty,
            'resource'  => $this->resourceId,
            'search'    => $this->search,
        ];
    }

    /** Parâmetros de URL equivalentes (impressão com os mesmos filtros). */
    public function query(): array
    {
        return array_filter([
            'date'      => $this->date->format('Y-m-d'),
            'doctor'    => $this->doctorId,
            'bout'      => $this->bout > 1 ? $this->bout : null,
            'status'    => $this->status,
            'specialty' => $this->specialty,
            'resource'  => $this->resourceId,
            'search'    => $this->search !== '' ? $this->search : null,
        ], fn ($value) => $value !== null);
    }
}
