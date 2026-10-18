<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\Exceptions\Fiscal\FocusNfeException;
use App\Models\Fiscal\NfseDocument;
use Illuminate\Support\Facades\{Log, Storage};
use InvalidArgumentException;

/**
 * XML da NFS-e (autorização e cancelamento) no disco padrão do projeto (`Storage::disk()`), um arquivo por nota:
 * `nfse/{ambiente}/{Y}/{m}/{ref}.xml` e `{ref}-cancel.xml` (ano/mês da criação da nota, estável). É a guarda fiscal de
 * 5 anos: o runbook exige backup do volume (ou bucket com versionamento) — não existe rotina de expurgo.
 *
 * O XML vem da Focus (caminho relativo devolvido na consulta/webhook) baixado pelo servidor com o token Basic. Nunca
 * sai por URL pública: o único acesso da clínica é a rota autenticada que lê daqui.
 *
 * O PDF (DANFSe) não é guardado: `pdf_url` é um link S3 público da própria Focus.
 */
final class NfseDocumentStorage
{
    public const KIND_AUTHORIZED = 'authorized';

    public const KIND_CANCEL = 'cancel';

    /** NFS-e real tem poucos KB; acima disso não é XML de nota. */
    private const MAX_BYTES = 5_000_000;

    public function __construct(private readonly NfseCredentialResolver $credentials)
    {
    }

    /**
     * Grava o XML e aponta a nota para ele. Conteúdo que não é XML bem formado (página de erro, vazio) é recusado.
     *
     * @throws InvalidArgumentException conteúdo que não é XML ou kind desconhecido
     */
    public function storeXml(NfseDocument $document, string $contents, string $kind): string
    {
        $column = self::column($kind);

        if (! self::isXml($contents)) {
            throw new InvalidArgumentException('O arquivo recebido da Focus não é um XML válido.');
        }

        $path = $this->path($document, $kind);

        Storage::disk()->put($path, $contents);

        if ($document->{$column} !== $path) {
            $document->forceFill([$column => $path])->save();
        }

        return $path;
    }

    /** Conteúdo guardado no disco; null se a nota não tem arquivo ou ele sumiu. */
    public function contents(NfseDocument $document, string $kind): ?string
    {
        $path = $document->{self::column($kind)};

        if (blank($path) || ! Storage::disk()->exists($path)) {
            return null;
        }

        $contents = Storage::disk()->get($path);

        return is_string($contents) && $contents !== '' ? $contents : null;
    }

    /**
     * O XML da nota: do disco; se não estiver (webhook que veio sem arquivo, restauração de backup, cancelamento
     * posterior), busca na Focus pelo caminho que ela devolveu, guarda e entrega. Null = não existe ainda (nota sem
     * caminho) ou a Focus não entregou — o chamador decide entre 404 e tentar de novo depois.
     */
    public function ensure(NfseDocument $document, string $kind): ?string
    {
        $stored = $this->contents($document, $kind);

        if ($stored !== null) {
            return $stored;
        }

        $relative = (string) data_get(
            $document->response_payload,
            $kind === self::KIND_CANCEL ? 'caminho_xml_cancelamento' : 'caminho_xml_nota_fiscal',
        );

        if ($relative === '') {
            return null;
        }

        try {
            $xml = $this->credentials->client($document->environment)->download($relative);

            $this->storeXml($document, $xml, $kind);

            return $xml;
        } catch (FocusNfeException|InvalidArgumentException $e) {
            // Falha ao baixar não derruba quem chamou: a nota continua válida e o arquivo é buscado de novo depois.
            Log::warning('nfse.xml.unavailable', ['ref' => $document->ref, 'kind' => $kind, 'codigo' => $e instanceof FocusNfeException ? $e->errorCode : 'xml_invalido']);

            return null;
        }
    }

    /** Caminho no disco: nfse/{ambiente}/{Y}/{m}/{ref}.xml (ou -cancel). */
    public function path(NfseDocument $document, string $kind): string
    {
        self::column($kind); // valida o tipo

        $when   = $document->created_at ?? now();
        $folder = trim((string) config('fiscal.storage_path', 'nfse'), '/');
        $suffix = $kind === self::KIND_CANCEL ? '-cancel' : '';

        return sprintf('%s/%s/%s/%s/%s%s.xml', $folder, $document->environment->value, $when->format('Y'), $when->format('m'), $document->ref, $suffix);
    }

    /** Nome do arquivo na resposta de download: nfse-{número|ref}[-cancelamento].xml */
    public function downloadName(NfseDocument $document, string $kind): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($document->numero ?: $document->ref)) ?: 'nota';

        return 'nfse-' . $base . ($kind === self::KIND_CANCEL ? '-cancelamento' : '') . '.xml';
    }

    private static function column(string $kind): string
    {
        return match ($kind) {
            self::KIND_AUTHORIZED => 'xml_path',
            self::KIND_CANCEL     => 'cancel_xml_path',
            default               => throw new InvalidArgumentException("Tipo de XML desconhecido: {$kind}"),
        };
    }

    private static function isXml(string $contents): bool
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES || ! str_starts_with(ltrim($contents), '<')) {
            return false;
        }

        $previous = libxml_use_internal_errors(true);
        $xml      = simplexml_load_string($contents, options: LIBXML_NONET);

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml !== false;
    }
}
