<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\DataAccessPurpose;
use App\Models\MedicalRecordDocumentation;
use App\Services\MedicalRecordPdfService;
use App\Traits\LogsDataAccess;
use Illuminate\Http\Response;

/**
 * Laudo de imagem pro médico solicitante (Fase 5) — rota pública
 * (routes/external-reports.php), sem conta/portal. O link assinado
 * (`signed` middleware, HMAC com APP_KEY) é o ÚNICO controle de acesso: sem
 * Gate/tenant scope aqui, porque MedicalRecordDocumentation não tem
 * entity_id (confirmado no model — nunca usar BelongsToEntity nele).
 *
 * 404 (nunca 403) quando não há e-mail de solicitante gravado: o link só
 * existe pra quem foi de fato endereçado a um médico solicitante — qualquer
 * outro documento "não existe" por esta rota, ponto.
 *
 * 404 também enquanto o prontuário dono não está ASSINADO: um laudo que a
 * clínica ainda pode editar ou excluir não é entregue a terceiros (achado via
 * revisão adversarial Codex) — o mesmo link volta a abrir quando o prontuário
 * é assinado. Mesma regra do envio (EyeImageReportResendController) e do
 * compartilhamento com o paciente.
 */
class ExternalMedicalReportController extends Controller
{
    use LogsDataAccess;

    public function __invoke(MedicalRecordDocumentation $documentation, MedicalRecordPdfService $pdf): Response
    {
        abort_if(blank($documentation->requesting_doctor_email), 404);
        abort_unless($documentation->isExternallyDeliverable(), 404, __('eye_images.external_report_not_final'));

        // Visitante anônimo (sem session('selected_entity_id')) — ou, pior,
        // autenticado no painel de OUTRA clínica numa aba ao lado — nunca tem
        // a sessão certa pra esta documentação; patient_id é coluna direta e
        // sempre confiável, nunca derivada de sessão (achado via revisão
        // Codex: sem isto, o acesso ficava sem clínica no log de auditoria,
        // ou pior, saía atribuído à clínica errada).
        $this->logAccess($documentation, DataAccessPurpose::Report, entityId: $documentation->patient?->entity_id);

        return $pdf->generateDocumentation($documentation);
    }
}
