<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ScheduleResource;
use App\Models\Schedule;
use App\Support\IntegratorClinicalIdentifier;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Eloquent\{Builder, ModelNotFoundException};
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class SchedulesController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected Schedule $model;

    public function __construct(Schedule $schedule)
    {
        $this->model = $schedule;
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $integrator = request()->attributes->get('integrator');
        $search     = request()->string('search')->trim()->value();

        $schedules = $this->model->query()
            ->with(['doctor', 'patient.person', 'resources', 'covenant', 'visitType'])
            ->where('entity_id', $integrator->user->entity_id);

        // Escopo opcional a um recurso de agenda específico (ex.: o
        // equipamento que está consultando via Modality Worklist), via
        // schedule_resources. Ausente = comportamento idêntico ao de antes
        // desta mudança (agenda inteira do dia da entidade).
        if (request()->filled('clinic_resource_id')) {
            $clinicResourceId = request()->string('clinic_resource_id')->value();
            $schedules        = $schedules->whereHas(
                'resources',
                fn ($q) => $q->where('clinic_resources.id', $clinicResourceId),
            );
        }

        $identifierSearch = $this->resolveIdentifierSearch($search);

        if (request()->has('date')) {
            $date      = Carbon::parse(request()->date)->toDateString();
            $schedules = $schedules->whereDate('date_time', $date);
        } elseif ($identifierSearch === null) {
            $schedules = $schedules->whereDate('date_time', now()->toDateString());
        }

        if ($identifierSearch !== null) {
            $schedules = $schedules->where($identifierSearch['where']);
        } elseif (filled($search)) {
            $schedules = $schedules->where(function ($query) use ($search) {
                $query->whereHas('patient', function ($q) use ($search) {
                    $q->whereHas('person', function ($qq) use ($search) {
                        $qq->whereLikeUnaccent('full_name', $search)
                            ->orWhereLikeUnaccent('nickname', $search);
                    });
                })
                    ->orWhereHas('doctor', function ($q) use ($search) {
                        $q->whereHas('person', function ($qq) use ($search) {
                            $qq->whereLikeUnaccent('full_name', $search)
                                ->orWhereLikeUnaccent('nickname', $search);
                        });
                    })
                    ->orWhereHas('covenant', function ($q) use ($search) {
                        $q->whereLikeUnaccent('name', $search);
                    })
                    ->orWhereLikeUnaccent('code', $search)
                    ->orWhereLikeUnaccent('full_name', $search);
            });
        }

        $schedules = $schedules->paginate($this->perPage());

        return ScheduleResource::collection($schedules);
    }

    /**
     * @return array{where: Closure(Builder): void}|null
     */
    private function resolveIdentifierSearch(?string $search): ?array
    {
        if (blank($search)) {
            return null;
        }

        if (Str::isUuid($search)) {
            return ['where' => fn (Builder $query) => $query->where('id', $search)];
        }

        if (ctype_digit($search)) {
            $formattedCode = sprintf('SDL-%010d', (int) $search);

            // Número puro: pode ser o código interno (SDL-0000000042) OU o
            // código do sistema anterior do integrador (import_code costuma
            // ser só numérico em sistemas legados) — tenta os dois.
            return ['where' => fn (Builder $query) => $query->where(function ($q) use ($formattedCode, $search) {
                $q->where('code', $formattedCode)
                    ->orWhere('import_code', $search);
            })];
        }

        $normalizedCode = mb_strtoupper($search, 'UTF-8');

        if (preg_match('/^SDL-\d{1,10}$/', $normalizedCode) === 1) {
            $numericPart   = (int) substr($normalizedCode, 4);
            $formattedCode = sprintf('SDL-%010d', $numericPart);

            return ['where' => fn (Builder $query) => $query->where(function ($q) use ($formattedCode, $search) {
                $q->where('code', $formattedCode)
                    ->orWhere('import_code', $search);
            })];
        }

        return null;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $idOrCode): ScheduleResource
    {
        $integrator = request()->attributes->get('integrator');

        // UUID, SDL-N, número puro ou import_code — ver Schedule::identifierMatches().
        // Nunca devolve uma linha arbitrária: se o identificador casar com mais
        // de um agendamento (número = SDL-N de um e import_code de outro, ou
        // código duplicado), responde 409 e o desktop deve usar o UUID.
        $matches = IntegratorClinicalIdentifier::matches(
            Schedule::class,
            (string) $integrator->user->entity_id,
            $idOrCode,
            'SDL',
            request()->query('identifier_namespace'),
        );
        $matches->load(['doctor', 'patient.person', 'resources', 'covenant', 'visitType']);

        if ($matches->isEmpty()) {
            throw (new ModelNotFoundException())->setModel(Schedule::class, [$idOrCode]);
        }

        abort_if(
            $matches->count() > 1,
            HttpResponse::HTTP_CONFLICT,
            __('record_codes.ambiguous_identifier.schedule'),
        );

        return new ScheduleResource($matches->first());
    }
}
