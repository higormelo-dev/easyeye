<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DataAccessPurpose;
use App\Models\{DataAccessLog, Patient};
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Log;

class DataAccessLogService
{
    /**
     * Registra o acesso (leitura) a um recurso sensível.
     * CFM Res. 2.227/2018 + LGPD Art. 37.
     *
     * @param Model             $resource      Model acessado (MedicalRecord, PatientExam…)
     * @param DataAccessPurpose $purpose       Finalidade do acesso
     * @param string|null       $justification Obrigatório para EmergencyAccess
     * @param string|null       $patientId     UUID do paciente; inferido automaticamente se possível
     */
    public function log(
        Model $resource,
        DataAccessPurpose $purpose,
        ?string $justification = null,
        ?string $patientId = null,
    ): void {
        if ($purpose->requiresJustification() && blank($justification)) {
            Log::warning('DataAccessLogService: acesso emergencial sem justificativa', [
                'resource' => get_class($resource),
                'id'       => $resource->getKey(),
                'user_id'  => AuditContext::userId(),
            ]);
        }

        DataAccessLog::create([
            'entity_id' => session('selected_entity_id') ?? $resource->getAttribute('entity_id'),
            ...AuditContext::integratorActor(),
            ...AuditContext::patientAccountActor(),
            'user_id'       => AuditContext::userId(),
            'resource_type' => get_class($resource),
            'resource_id'   => $resource->getKey(),
            'patient_id'    => $patientId ?? $this->inferPatientId($resource),
            'purpose'       => $purpose,
            'justification' => $justification,
            'ip_address'    => request()->ip(),
            'user_agent'    => request()->userAgent(),
            'accessed_at'   => now(),
        ]);
    }

    /**
     * Tenta inferir o patient_id do model: o próprio paciente (lista de
     * prontuários, laudo de imagens…) ou o atributo patient_id. Sem isso o log
     * de um acesso feito pelo Patient ficava fora de Patient::accessLogs().
     */
    /**
     * Mesmo registro de log() para vários recursos de uma vez (ex.: a agenda
     * impressa — um registro por agendamento, ligado ao paciente quando há
     * cadastro). Um INSERT só.
     *
     * @param iterable<Model> $resources
     */
    public function logMany(iterable $resources, DataAccessPurpose $purpose, ?string $justification = null): void
    {
        $now  = now();
        $rows = [];

        foreach ($resources as $resource) {
            $rows[] = [
                'id'        => (string) Str::orderedUuid(),
                'entity_id' => session('selected_entity_id') ?? $resource->getAttribute('entity_id'),
                ...AuditContext::integratorActor(),
                ...AuditContext::patientAccountActor(),
                'user_id'        => AuditContext::userId(),
                'resource_type'  => get_class($resource),
                'resource_id'    => $resource->getKey(),
                'patient_id'     => $this->inferPatientId($resource),
                'access_summary' => null,
                'purpose'        => $purpose->value,
                'justification'  => $justification,
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
                'accessed_at'    => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DataAccessLog::query()->insert($chunk);
        }
    }

    public function aggregate(string $kind, int $count, array $filters = []): void
    {
        $integrator = request()->attributes->get('integrator');

        if (! $integrator) {
            return;
        }
        DataAccessLog::create(['entity_id' => $integrator->user->entity_id, 'user_id' => null, 'resource_type' => $kind, 'resource_id' => $integrator->id, 'purpose' => DataAccessPurpose::ApiAccess, 'access_summary' => ['count' => $count, 'filters' => array_intersect_key($filters, array_flip(['date', 'clinic_resource_id', 'date_from', 'date_to', 'page']))], 'ip_address' => request()->ip(), 'user_agent' => substr((string) request()->userAgent(), 0, 512), 'accessed_at' => now(), ...AuditContext::integratorActor()]);
    }

    private function inferPatientId(Model $resource): ?string
    {
        if ($resource instanceof Patient) {
            return (string) $resource->getKey();
        }

        if ($resource->getAttribute('patient_id')) {
            return $resource->getAttribute('patient_id');
        }

        return null;
    }
}
