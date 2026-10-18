<?php

declare(strict_types=1);

namespace App\Services\Fiscal;

use App\DTOs\Fiscal\NfseMunicipality;
use App\Enums\Fiscal\NfseEnvironment;
use App\Exceptions\Fiscal\FocusNfeException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Consulta de município na Focus (token MASTER): obrigatoriedades da NFS-e, cancelamento por webservice e busca pelo
 * nome. As obrigatoriedades mudam pouco, então ficam em cache por ambiente (`fiscal.municipality.cache_hours`, 24 h):
 * fonte das guardas de emissão (endereço do tomador) e do aviso de cancelamento.
 *
 * A cópia guardada vive 7 dias além da validade: se a Focus estiver fora do ar, a tela e as guardas seguem com o último
 * valor conhecido (`stale = true`, com a data) em vez de travar — erro de rede vira aviso, não bloqueio.
 */
final class NfseMunicipalityService
{
    /** Quanto tempo a cópia fica guardada (a validade "fresca" é menor e vem da configuração). */
    private const KEEP_DAYS = 7;

    public function __construct(private readonly NfseCredentialResolver $credentials)
    {
    }

    /**
     * Obrigatoriedades do município. Fresco do cache, ou busca na Focus (`$refresh` força a busca).
     *
     * @throws FocusNfeException sem token master, token recusado, município inexistente (nao_encontrado) ou Focus fora do ar sem cópia guardada
     */
    public function obligations(string $ibge, NfseEnvironment $environment, bool $refresh = false): NfseMunicipality
    {
        $ibge   = self::normalize($ibge);
        $cached = $this->cached($ibge, $environment);

        if ($cached !== null && ! $refresh && $this->isFresh($cached)) {
            return $cached;
        }

        try {
            $data = $this->credentials->masterClient($environment)->municipality($ibge);
        } catch (FocusNfeException $e) {
            // Só queda de rede/servidor usa a cópia velha; token recusado ou município inexistente o usuário precisa ver.
            if ($cached !== null && $e->retryable()) {
                return $cached->withStale(true);
            }

            throw $e;
        }

        $municipality = NfseMunicipality::fromFocus($ibge, $data, now()->toIso8601String());

        Cache::put($this->key($ibge, $environment), $municipality->toArray(), now()->addHours($this->cacheHours())->addDays(self::KEEP_DAYS));

        return $municipality;
    }

    /** O que já se sabe do município, sem chamar a Focus (guardas de emissão). Null = nunca consultado. */
    public function cached(string $ibge, NfseEnvironment $environment): ?NfseMunicipality
    {
        $data = Cache::get($this->key(self::normalize($ibge), $environment));

        if (! is_array($data)) {
            return null;
        }

        return NfseMunicipality::fromFocus(
            self::normalize($ibge),
            $data,
            isset($data['fetched_at']) ? (string) $data['fetched_at'] : null,
        );
    }

    /**
     * O código IBGE existe na base da Focus? Valida a digitação do IBGE da clínica antes de gravar (404 = não existe).
     *
     * @throws FocusNfeException sem token master, token recusado ou Focus fora do ar
     */
    public function exists(string $ibge, NfseEnvironment $environment): bool
    {
        try {
            $this->obligations($ibge, $environment, refresh: true);

            return true;
        } catch (FocusNfeException $e) {
            if ($e->errorCode === FocusNfeException::NOT_FOUND) {
                return false;
            }

            throw $e;
        }
    }

    /**
     * Busca por parte do nome e/ou UF, para a tela achar o IBGE sem o usuário digitar de memória.
     *
     * @return list<array{codigo_municipio: string, nome_municipio: string, sigla_uf: string, nfse_habilitada: bool}>
     *
     * @throws FocusNfeException
     */
    public function search(NfseEnvironment $environment, ?string $name, ?string $uf): array
    {
        $rows = $this->credentials->masterClient($environment)->searchMunicipalities($name, $uf);

        $found = [];

        foreach ($rows as $row) {
            $code = (string) ($row['codigo_municipio'] ?? '');

            if (preg_match('/^\d{7}$/', $code) !== 1) {
                continue;
            }

            $found[] = [
                'codigo_municipio' => $code,
                'nome_municipio'   => (string) ($row['nome_municipio'] ?? ''),
                'sigla_uf'         => (string) ($row['sigla_uf'] ?? ''),
                'nfse_habilitada'  => (bool) ($row['nfse_habilitada'] ?? false),
            ];
        }

        return $found;
    }

    private function isFresh(NfseMunicipality $municipality): bool
    {
        if ($municipality->fetchedAt === null) {
            return false;
        }

        return now()->subHours($this->cacheHours())->lessThan($municipality->fetchedAt);
    }

    private function cacheHours(): int
    {
        return max(1, (int) config('fiscal.municipality.cache_hours', 24));
    }

    private function key(string $ibge, NfseEnvironment $environment): string
    {
        return "nfse:municipality:{$environment->value}:{$ibge}";
    }

    private static function normalize(string $ibge): string
    {
        $digits = preg_replace('/\D/', '', $ibge) ?? '';

        if (strlen($digits) !== 7) {
            throw new InvalidArgumentException('O código IBGE do município tem 7 dígitos.');
        }

        return $digits;
    }
}
