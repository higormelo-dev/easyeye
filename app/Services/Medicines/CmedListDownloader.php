<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\{RequestInterface, ResponseInterface, UriInterface};
use RuntimeException;
use Throwable;

/**
 * Baixa as fontes oficiais do catálogo de medicamentos:
 *
 * - Lista de preços CMED (PMC): o link muda a cada publicação
 *   (lista_pmc_AAAAMMDD_SEQ.xlsx), então é lido da página oficial da CMED e
 *   vale o mais recente. Página fora do ar/sem o link (layout mudou) → a
 *   mesma lista em CSV no portal de dados abertos da Anvisa (endereço fixo,
 *   mas atualizado com atraso — a tela avisa a data).
 * - DADOS_ABERTOS_MEDICAMENTOS.csv: situação dos registros (diário).
 *
 * Segurança: só HTTPS e só os hosts de config('medicines.cmed.allowed_hosts')
 * — inclusive em redirecionamento —, teto de tamanho antes e depois de
 * baixar e conferência do formato (XLSX é ZIP; página de erro em HTML não
 * passa). Arquivo vai direto para o disco (sink), nunca para a memória.
 */
class CmedListDownloader
{
    private const USER_AGENT = 'EasyEye/1.0 (+https://easyeye.app) catalog-sync';

    /** href da lista PMC na página oficial (relativo ou absoluto). */
    private const LIST_LINK = '#href="(?<href>[^"]*?/lista_pmc_(?<date>\d{8})_(?<seq>\d+)\.(?<ext>xlsx|xls)(?:/@@download/file)?)"#i';

    /**
     * Lista mais recente. Página indisponível ou sem o link → reserva.
     *
     * @throws CmedDownloadException quando nem a reserva responde
     */
    public function latestList(): CmedListRef
    {
        return $this->fromOfficialPage() ?? $this->fallbackList();
    }

    /** Lista de reserva (portal de dados abertos), versão pela data de publicação do arquivo. */
    public function fallbackList(): CmedListRef
    {
        $url      = (string) config('medicines.cmed.fallback_list_url');
        $modified = $this->lastModified($url);

        if ($modified === null) {
            throw new CmedDownloadException(__('manager_medicines.sync_list_unavailable'));
        }

        return new CmedListRef(
            url: $url,
            version: 'dados_abertos_' . $modified->format('Ymd'),
            publishedAt: $modified->startOfDay(),
            filename: basename((string) parse_url($url, PHP_URL_PATH)),
            extension: 'csv',
            fallback: true,
        );
    }

    /** Versão atual dos dados abertos de medicamentos (Last-Modified), sem baixar. */
    public function openDataVersion(): ?string
    {
        return $this->lastModified((string) config('medicines.cmed.open_data_url'))?->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Baixa para um arquivo local temporário e confere o formato. O chamador
     * apaga o arquivo.
     *
     * @throws CmedDownloadException
     */
    public function download(string $url, string $extension): string
    {
        $this->assertAllowed($url);

        $max  = (int) config('medicines.cmed.file_max_bytes');
        $path = tempnam(sys_get_temp_dir(), 'cmedsync_');

        if ($path === false) {
            throw new CmedDownloadException(__('manager_medicines.sync_download_failed'));
        }

        try {
            $response = $this->client()
                ->sink($path)
                ->withOptions([
                    // Corta antes de baixar um arquivo maior que o teto.
                    'on_headers' => function (ResponseInterface $response) use ($max) {
                        if ((int) $response->getHeaderLine('Content-Length') > $max) {
                            throw new RuntimeException('file too large');
                        }
                    },
                ])
                ->get($url);
        } catch (Throwable) {
            @unlink($path);

            throw new CmedDownloadException(__('manager_medicines.sync_download_failed'));
        }

        clearstatcache(true, $path);
        $size = (int) @filesize($path);

        if (! $response->successful() || $size === 0 || $size > $max || ! $this->looksLike($path, $extension)) {
            @unlink($path);

            throw new CmedDownloadException(__('manager_medicines.sync_download_failed'));
        }

        return $path;
    }

    /**
     * Data de publicação gravada no preâmbulo da lista em CSV ("Publicada em
     * 21/07/2026 17h30min.") — mais exata que a data do arquivo.
     */
    public function publishedAtFromCsv(string $path): ?CarbonImmutable
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return null;
        }

        try {
            for ($i = 0; $i < 15 && ($line = fgets($handle)) !== false; $i++) {
                if (preg_match('#Publicada em (\d{2})/(\d{2})/(\d{4})#u', $line, $m) === 1) {
                    return CarbonImmutable::createSafe((int) $m[3], (int) $m[2], (int) $m[1]) ?: null;
                }
            }
        } catch (Throwable) {
            return null;
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function fromOfficialPage(): ?CmedListRef
    {
        $url = (string) config('medicines.cmed.page_url');

        try {
            $this->assertAllowed($url);
            $response = $this->client()->get($url);
        } catch (Throwable) {
            return null;
        }

        $html = (string) $response->body();

        if (! $response->successful() || $html === '' || strlen($html) > (int) config('medicines.cmed.page_max_bytes')) {
            return null;
        }

        if (preg_match_all(self::LIST_LINK, $html, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        $host  = (string) parse_url($url, PHP_URL_HOST);
        $links = [];

        // Só links de host permitido entram na disputa (link externo "mais novo" é ignorado).
        foreach ($matches as $match) {
            $href = html_entity_decode($match['href'], ENT_QUOTES | ENT_HTML5);
            $link = str_starts_with($href, '/') ? 'https://' . $host . $href : $href;

            try {
                $this->assertAllowed($link);
            } catch (Throwable) {
                continue;
            }

            $links[] = [...$match, 'link' => $link];
        }

        if ($links === []) {
            return null;
        }

        // Mais recente: maior data, depois maior sequência (mais de uma publicação no dia).
        usort($links, fn ($a, $b) => [$b['date'], (int) $b['seq']] <=> [$a['date'], (int) $a['seq']]);
        $latest = $links[0];
        $link   = $latest['link'];

        $published = CarbonImmutable::createFromFormat('!Ymd', $latest['date']) ?: null;

        return new CmedListRef(
            url: $link,
            version: $latest['date'] . '_' . $latest['seq'],
            publishedAt: $published,
            filename: 'lista_pmc_' . $latest['date'] . '_' . $latest['seq'] . '.' . strtolower($latest['ext']),
            extension: strtolower($latest['ext']),
        );
    }

    private function lastModified(string $url): ?CarbonImmutable
    {
        try {
            $this->assertAllowed($url);
            $response = $this->client()->head($url);
        } catch (Throwable) {
            return null;
        }

        $header = $response->successful() ? $response->header('Last-Modified') : '';

        try {
            return $header !== '' ? CarbonImmutable::parse($header)->utc() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function client(): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)
            ->timeout((int) config('medicines.cmed.timeout_seconds', 300))
            ->connectTimeout(30)
            ->retry(2, 3000, throw: false)
            ->withOptions([
                'allow_redirects' => [
                    'max'         => 3,
                    'protocols'   => ['https'],
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
                        $this->assertAllowed((string) $uri);
                    },
                ],
            ]);
    }

    /** Só HTTPS e só hosts oficiais configurados. */
    private function assertAllowed(string $url): void
    {
        $parts = parse_url($url);
        $host  = strtolower((string) ($parts['host'] ?? ''));

        if (($parts['scheme'] ?? '') !== 'https' || ! in_array($host, (array) config('medicines.cmed.allowed_hosts'), true)) {
            throw new CmedDownloadException(__('manager_medicines.sync_download_failed'));
        }
    }

    /** XLSX é ZIP ("PK"); CSV não pode ser página HTML de erro. */
    private function looksLike(string $path, string $extension): bool
    {
        $head = (string) @file_get_contents($path, false, null, 0, 512);

        return match ($extension) {
            'xlsx'  => str_starts_with($head, 'PK'),
            'xls'   => str_starts_with($head, "\xD0\xCF\x11\xE0"),
            default => $head !== '' && stripos($head, '<html') === false && stripos($head, '<!doctype') === false,
        };
    }
}
