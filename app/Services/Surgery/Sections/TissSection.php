<?php

declare(strict_types=1);

namespace App\Services\Surgery\Sections;

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Services\Transport\{HttpTissTransportClient, TissMessageTransportContract};
use App\Domains\Tiss\Surgical\{SurgicalGuideService, SurgicalGuideView};
use App\Enums\Surgery\SurgicalCaseStatus;
use App\Models\Surgery\SurgicalCase;

/**
 * Aba "TISS": solicitação de internação e anexo de OPME do pedido cirúrgico (M3b).
 * Só para caso com convênio REAL e (regime com internação OU OPME). Financeiro não abre
 * o caso (a tela do caso é de admin/médico/secretária), então a aba é desses perfis.
 */
final class TissSection implements SurgeryShowSection
{
    public function __construct(
        private readonly SurgicalGuideService $guides,
        private readonly SurgicalGuideView $view,
        private readonly TissMessageTransportContract $transport,
    ) {
    }

    public function key(): string
    {
        return 'tiss';
    }

    public function order(): int
    {
        return 50;
    }

    public function label(): string
    {
        return (string) __('surgery_tiss.tab');
    }

    public function visible(SurgicalCase $case, array $can): bool
    {
        $case->loadMissing('covenant');

        return $case->requiresInsurer() && ($case->regime->requiresInpatientRequest() || $case->needs_opme);
    }

    public function props(SurgicalCase $case, array $can): array
    {
        $inpatient = $this->guides->current($case, TissGuideType::InpatientRequest);
        $opme      = $this->guides->current($case, TissGuideType::OpmeAttachment);
        $caseOk    = in_array($case->status, SurgicalGuideService::CASE_STATUSES, true);

        return [
            'operator' => [
                'name'      => $case->covenant?->name,
                'transport' => $this->transport instanceof HttpTissTransportClient ? 'http' : 'mock',
            ],
            'case_awaiting' => $case->status === SurgicalCaseStatus::AwaitingAuthorization,
            'available'     => [
                'inpatient' => $case->regime->requiresInpatientRequest(),
                'opme'      => (bool) $case->needs_opme,
            ],
            'can' => [
                'generate' => $can['manage'] && $caseOk,
                'act'      => $can['manage'],
            ],
            'guides' => [
                'inpatient' => $inpatient === null ? null : $this->view->present($case, $inpatient),
                'opme'      => $opme === null ? null : $this->view->present($case, $opme),
            ],
            'table_codes' => ['18', '19', '20', '22', '90', '98', '00'],
            'urls'        => [
                'store'    => route('panel.surgery.tiss.guides.store', $case),
                'guide'    => route('panel.surgery.tiss.guides.update', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'refresh'  => route('panel.surgery.tiss.guides.refresh', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'xml_make' => route('panel.surgery.tiss.guides.xml.generate', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'xml'      => route('panel.surgery.tiss.guides.xml.download', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'pdf'      => route('panel.surgery.tiss.guides.pdf', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'send'     => route('panel.surgery.tiss.guides.send', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'reopen'   => route('panel.surgery.tiss.guides.reopen', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'cancel'   => route('panel.surgery.tiss.guides.cancel', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
                'response' => route('panel.surgery.tiss.guides.response', ['surgicalCase' => $case, 'guide' => '__GUIDE__']),
            ],
        ];
    }
}
