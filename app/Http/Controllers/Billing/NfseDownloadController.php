<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\Fiscal\NfseStatus;
use App\Http\Controllers\Controller;
use App\Models\Entity;
use App\Models\Fiscal\NfseDocument;
use App\Services\Fiscal\NfseDocumentStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Minha assinatura → baixar o XML da NFS-e da própria clínica (autorizada ou cancelada). O XML sai SEMPRE por aqui: o
 * servidor lê o arquivo guardado (ou o busca na Focus com o token dele, se faltar) e entrega — o token nunca vai ao
 * navegador e o arquivo nunca tem URL pública. O PDF é o link S3 público da própria Focus (`pdf_url`).
 *
 * Isolamento: a nota de outra clínica responde 404 (nunca 403, não confirma que existe), conferido AQUI além do escopo
 * da query. Só nota autorizada/cancelada é visível à clínica; pendente, em erro ou em processamento não existe para ela.
 * Rota atrás de `billing.contact` (admin, financeiro e dono) e do limite `billing-checkout-read`.
 */
class NfseDownloadController extends Controller
{
    public function __construct(private readonly NfseDocumentStorage $storage)
    {
    }

    public function xml(Request $request, NfseDocument $document): StreamedResponse
    {
        return $this->download($request, $document, NfseDocumentStorage::KIND_AUTHORIZED);
    }

    public function cancelXml(Request $request, NfseDocument $document): StreamedResponse
    {
        abort_unless($document->status === NfseStatus::Cancelled && ! $document->cancelled_externally, 404);

        return $this->download($request, $document, NfseDocumentStorage::KIND_CANCEL);
    }

    private function download(Request $request, NfseDocument $document, string $kind): StreamedResponse
    {
        /** @var Entity $entity */
        $entity = $request->attributes->get('checkout_entity');

        abort_unless((string) $document->entity_id === (string) $entity->id, 404);
        abort_unless(in_array($document->status, [NfseStatus::Authorized, NfseStatus::Cancelled], true), 404);

        $contents = $this->storage->ensure($document, $kind);

        abort_if($contents === null, 404);

        return response()->streamDownload(fn () => print($contents), $this->storage->downloadName($document, $kind), [
            'Content-Type'           => 'application/xml',
            'Cache-Control'          => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
