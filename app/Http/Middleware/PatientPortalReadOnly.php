<?php

namespace App\Http\Middleware;

use App\Models\Patient;
use App\Services\Billing\ClinicServiceGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal do paciente em SÓ LEITURA para a clínica com o acesso bloqueado
 * (ClinicServiceGate): o paciente continua vendo e baixando os próprios
 * documentos e resultados (LGPD), mas nada que escreva na clínica (agendar,
 * enviar, alterar) passa. A clínica vem do {patient} da rota (o cadastro do
 * paciente naquela clínica) ou de `patient_id` no corpo.
 *
 * Mensagem neutra — nunca o motivo financeiro da clínica ao paciente.
 */
class PatientPortalReadOnly
{
    public function __construct(
        private readonly ClinicServiceGate $gate,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $patient = $request->route('patient');
        $patient = $patient instanceof Patient
            ? $patient
            : (is_string($candidate = $patient ?? $request->input('patient_id')) && $candidate !== ''
                ? Patient::query()->withoutGlobalScopes()->find($candidate)
                : null);

        if ($patient === null || ! $this->gate->portalReadOnly((string) $patient->entity_id)) {
            return $next($request);
        }

        $message = __('patient_portal.read_only.action_blocked');

        if ($request->expectsJson() && ! $request->hasHeader('X-Inertia')) {
            return response()->json(['message' => $message, 'code' => 'clinic_read_only'], Response::HTTP_LOCKED);
        }

        return redirect()->back()->with('error', $message);
    }
}
