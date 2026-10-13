<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\EntityGate;
use App\Models\{Entity, MedicalRecordDocumentation};
use App\Notifications\RequestingDoctorReportReady;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\{Gate, Notification};
use Throwable;

/**
 * Envia (ou reenvia) o laudo de imagem ao médico solicitante (Fase 5) — o
 * e-mail usado é sempre o gravado na emissão (sem campo de correção nesta
 * fase; ver Riscos no plano).
 *
 * É O ÚNICO caminho de envio: a emissão (EyeImageReportController::store)
 * só grava o destinatário, porque o laudo nasce dentro de um prontuário ainda
 * editável. Aqui o envio exige o prontuário ASSINADO (imutável) — achado via
 * revisão adversarial Codex: o e-mail com o link público saía na emissão e a
 * clínica ainda podia alterar ou excluir o laudo que o solicitante recebeu.
 */
class EyeImageReportResendController extends Controller
{
    public function __invoke(MedicalRecordDocumentation $documentation): JsonResponse
    {
        $entityId = (string) session('selected_entity_id');
        Gate::authorize(EntityGate::SendReportToExternalDoctor->value, Entity::findOrFail($entityId));

        // MedicalRecordDocumentation não tem entity_id (sem BelongsToEntity) —
        // posse verificada explicitamente pela cadeia até o paciente, nunca só
        // pelo binding da rota (mesmo raciocínio de
        // MedicalRecordDocumentationsController::assertRecordBelongsToPatient).
        $documentation->loadMissing('patient');
        abort_if((string) $documentation->patient?->entity_id !== $entityId, 404);

        abort_if(blank($documentation->requesting_doctor_email), 422, __('eye_images.report_resend_no_email'));

        abort_unless($documentation->isExternallyDeliverable(), 422, __('eye_images.report_resend_record_not_signed'));

        try {
            // sendNow() — nunca ->notify() (a notification nem implementa mais
            // ShouldQueue): reenviar é um comando explícito de "mandar AGORA",
            // e o botão só deve ficar "Enviado" se o canal mail de fato
            // confirmou. ->notify() só enfileiraria e este endpoint marcaria
            // sent_at mesmo com a fila parada (achado via revisão Codex).
            Notification::sendNow(
                Notification::route('mail', $documentation->requesting_doctor_email),
                new RequestingDoctorReportReady(
                    documentationId: (string) $documentation->id,
                    requestingDoctorName: (string) ($documentation->requesting_doctor_name ?: __('eye_images.requesting_doctor_default_name')),
                    patientName: (string) $documentation->patient?->person?->full_name,
                    clinicName: (string) (Entity::find($entityId)?->name),
                ),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('eye_images.requesting_doctor_resend_failed')], 502);
        }

        // O e-mail JÁ FOI ENVIADO (sendNow() acima confirmou) quando chega
        // aqui — e o prontuário está assinado (a checagem lá em cima),
        // portanto gravar com update() normal SEMPRE bateria no guard de
        // GuardedBySignedRecord (bloqueia QUALQUER alteração em documento de
        // prontuário assinado — CFM 2.227/2018) e lançava 403: a tela
        // mostrava "falha no reenvio" pro médico mesmo o e-mail tendo saído,
        // incentivando reenviar de novo e duplicar o envio (achado via
        // revisão Codex). forceFill()+
        // saveQuietly() (mesmo padrão de Signable::sign()) grava sem passar
        // pelos eventos Eloquent — nunca afrouxa o guard pro CONTEÚDO clínico,
        // só pra este campo de bookkeeping de entrega. saveQuietly() também
        // pula o Auditable automático, por isso o log manual abaixo.
        $previousSentAt = $documentation->requesting_doctor_sent_at;
        $documentation->forceFill(['requesting_doctor_sent_at' => now()])->saveQuietly();

        app(AuditService::class)->log(
            event: 'updated',
            model: $documentation,
            old: ['requesting_doctor_sent_at' => $previousSentAt?->toIso8601String()],
            new: ['requesting_doctor_sent_at' => $documentation->requesting_doctor_sent_at->toIso8601String()],
        );

        return response()->json([
            'requesting_doctor_sent_at' => $documentation->requesting_doctor_sent_at->format('d/m/Y H:i'),
        ]);
    }
}
