<?php

declare(strict_types=1);

namespace App\Http\Controllers\Surgery;

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\TissGuide;
use App\Domains\Tiss\Surgical\{SendSurgicalGuideService, SurgicalAuthorizationResponseService, SurgicalGuidePdfService, SurgicalGuideService, SurgicalMessageXmlService};
use App\Enums\DataAccessPurpose;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Surgery\Concerns\AuthorizesSurgery;
use App\Http\Requests\Surgery\{SurgeryReasonRequest, SurgeryTissGuideRequest, SurgeryTissResponseRequest};
use App\Models\Surgery\SurgicalCase;
use App\Services\DataAccessLogService;
use Illuminate\Http\{RedirectResponse, Response};
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TISS do caso cirúrgico (M3b): solicitação de internação e anexo de OPME.
 *
 * Perfis: admin, médico e secretária (o pedido de autorização é deles — mesma regra de
 * SurgeryAuthorizationController; o financeiro fatura consulta/SP-SADT em lote e não
 * abre o caso). Médico só nos casos dele (authorizeCaseAccess). Toda guia é resolvida
 * DENTRO do caso e da clínica da sessão (IDOR: guia de outro caso/clínica = 404).
 */
class SurgeryTissController extends Controller
{
    use AuthorizesSurgery;

    public function __construct(
        private readonly SurgicalGuideService $guides,
        private readonly SurgicalMessageXmlService $xml,
        private readonly SendSurgicalGuideService $sender,
        private readonly SurgicalGuidePdfService $pdf,
        private readonly SurgicalAuthorizationResponseService $responses,
    ) {
    }

    public function store(SurgeryTissGuideRequest $request, SurgicalCase $surgicalCase): RedirectResponse
    {
        $this->authorizeManage();
        $this->authorizeCaseAccess($surgicalCase);

        $type = $request->input('type') === 'opme' ? TissGuideType::OpmeAttachment : TissGuideType::InpatientRequest;

        $this->guides->generate($surgicalCase, $type, $request->complement());

        return back()->with('success', __('surgery_tiss.messages.generated'));
    }

    public function update(SurgeryTissGuideRequest $request, SurgicalCase $surgicalCase, string $guide): RedirectResponse
    {
        $this->authorizeManage();

        $this->guides->complete($this->guide($surgicalCase, $guide), $request->complement());

        return back()->with('success', __('surgery_tiss.messages.updated'));
    }

    public function refresh(SurgicalCase $surgicalCase, string $guide): RedirectResponse
    {
        $this->authorizeManage();

        $this->guides->refresh($this->guide($surgicalCase, $guide));

        return back()->with('success', __('surgery_tiss.messages.refreshed'));
    }

    public function generateXml(SurgicalCase $surgicalCase, string $guide): RedirectResponse
    {
        $this->authorizeManage();

        $document = $this->xml->generate($this->guide($surgicalCase, $guide));

        if ($document->status->value !== 'validated' && $document->status->value !== 'signed') {
            throw SurgeryRuleException::invalid('surgery_tiss.errors.xml_rejected', context: ['errors' => array_slice((array) $document->validation_errors, 0, 5)]);
        }

        return back()->with('success', __('surgery_tiss.messages.xml_generated'));
    }

    public function downloadXml(SurgicalCase $surgicalCase, string $guide): StreamedResponse
    {
        $this->authorizeManage();

        $model    = $this->guide($surgicalCase, $guide);
        $document = $this->xml->currentDocument($model);

        abort_if($document === null, 404);

        $this->logAccess($model);

        $name = preg_replace('/[^A-Za-z0-9\-_.]/', '_', 'tiss-' . $model->guide_type->value . '-' . $model->guide_number_provider . '.xml');

        return response()->streamDownload(
            fn () => print((string) $document->xml_content),
            $name,
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }

    public function pdf(SurgicalCase $surgicalCase, string $guide): Response
    {
        $this->authorizeManage();

        $model = $this->guide($surgicalCase, $guide);

        $this->logAccess($model);

        return $this->pdf->render($model);
    }

    public function send(SurgicalCase $surgicalCase, string $guide): RedirectResponse
    {
        $this->authorizeManage();

        $transmission = $this->sender->send($this->guide($surgicalCase, $guide));

        return back()->with('success', __('surgery_tiss.messages.sent', ['protocol' => $transmission->protocol?->protocol_number ?? '—']));
    }

    public function reopen(SurgicalCase $surgicalCase, string $guide): RedirectResponse
    {
        $this->authorizeManage();

        $this->guides->reopen($this->guide($surgicalCase, $guide));

        return back()->with('success', __('surgery_tiss.messages.reopened'));
    }

    public function cancel(SurgeryReasonRequest $request, SurgicalCase $surgicalCase, string $guide): RedirectResponse
    {
        $this->authorizeManage();

        $this->guides->cancel($this->guide($surgicalCase, $guide), (string) $request->input('reason'));

        return back()->with('success', __('surgery_tiss.messages.cancelled'));
    }

    public function response(SurgeryTissResponseRequest $request, SurgicalCase $surgicalCase, string $guide): RedirectResponse
    {
        $this->authorizeManage();

        $model   = $this->guide($surgicalCase, $guide);
        $content = (string) file_get_contents($request->file('xml_file')->getRealPath());
        $result  = $this->responses->applyFromXml($model, $content, source: 'upload');

        if ($result === null) {
            return back()->withErrors(['xml_file' => __('surgery_tiss.errors.response_not_authorization')]);
        }

        return back()->with('success', __('surgery_tiss.messages.response_' . ($result['applied'] ? 'applied' : 'duplicate')));
    }

    /** XML e PDF carregam carteira e diagnóstico do paciente: o acesso entra na trilha LGPD. */
    private function logAccess(TissGuide $guide): void
    {
        app(DataAccessLogService::class)->log($guide, DataAccessPurpose::Administrative, patientId: $guide->patient_id ? (string) $guide->patient_id : null);
    }

    /** A guia pertence a ESTE caso e à clínica da sessão — senão 404 (nunca confirma que existe). */
    private function guide(SurgicalCase $case, string $guideId): TissGuide
    {
        $this->authorizeCaseAccess($case);

        return TissGuide::query()
            ->forEntity((string) $case->entity_id)
            ->where('surgical_case_id', $case->getKey())
            ->whereKey($guideId)
            ->firstOrFail();
    }
}
