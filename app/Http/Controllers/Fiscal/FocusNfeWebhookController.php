<?php

declare(strict_types=1);

namespace App\Http\Controllers\Fiscal;

use App\Enums\Fiscal\NfseStatus;
use App\Http\Controllers\Controller;
use App\Jobs\Fiscal\ProcessNfseWebhookJob;
use App\Models\Fiscal\NfseCredential;
use App\Support\Fiscal\NfsePayloadSanitizer;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Log;

/**
 * Webhook da Focus NFe (POST /api/fiscal/focusnfe/webhook/{token}): a Focus avisa cada mudança de status da nota. Rota
 * pública, sem sessão — a autenticação é do próprio desenho:
 *
 *  1. `{token}` (aleatório, na URL) escolhe a credencial/ambiente; nenhum casa → 404 genérico (não confirma o que existe);
 *  2. o SEGREDO, no cabeçalho `fiscal.webhook.header`, é comparado em tempo constante; diferente → 401. Sem segredo certo
 *     nada é aceito: o webhook só vale com segredo;
 *  3. com os dois certos, responde 200 NA HORA e manda o trabalho para a fila — a Focus retenta (1 min, 30 min, 1 h, 3 h,
 *     24 h) tudo que não for 2xx, então nunca devolvemos 4xx por causa do conteúdo (corpo sem `ref`, nota desconhecida).
 *
 * Idempotência e ordem são do NfseSyncService; aqui nada é gravado. Limite de chamadas por token: RateLimiter
 * `fiscal-webhook`.
 */
class FocusNfeWebhookController extends Controller
{
    /** Acima disso o corpo inteiro não vai para a fila (só os campos conhecidos): a nota real tem poucos KB. */
    private const MAX_PAYLOAD_BYTES = 65536;

    private const KNOWN_FIELDS = [
        'status', 'ref', 'cnpj_prestador', 'numero', 'codigo_verificacao', 'data_emissao', 'url', 'url_danfse',
        'caminho_xml_nota_fiscal', 'caminho_xml_cancelamento', 'erros', 'numero_rps', 'serie_rps', 'tipo_rps', 'protocolo',
    ];

    public function __invoke(Request $request, string $token): JsonResponse
    {
        $credential = $this->credentialFor($token);

        if ($credential === null) {
            abort(404);
        }

        $secret = (string) $credential->webhook_secret;
        $sent   = (string) $request->header((string) config('fiscal.webhook.header', 'X-FocusNfe-Secret'), '');

        if ($secret === '' || ! hash_equals($secret, $sent)) {
            abort(401);
        }

        $payload = $request->json()->all();
        $ref     = $payload['ref'] ?? null;

        if (! is_string($ref) || trim($ref) === '') {
            // Nunca 4xx para a Focus por causa do conteúdo: ela retentaria por até 48 h um corpo que nunca vai servir.
            Log::info('nfse.webhook.without_ref', ['environment' => $credential->environment->value]);

            return response()->json(['ok' => true]);
        }

        $ref = trim($ref);

        // `ref` é nossa (32 alfanuméricos); formato fora disso, ou status que não é um dos da Focus, nunca vai para a fila nem
        // para o log com o valor recebido (injeção de linha/lixo): 200 e só o fato fica registrado.
        if (preg_match('/^[A-Za-z0-9]{1,40}$/', $ref) !== 1) {
            Log::info('nfse.webhook.invalid_ref', ['environment' => $credential->environment->value]);

            return response()->json(['ok' => true]);
        }

        if (! is_string($payload['status'] ?? null) || NfseStatus::fromFocus($payload['status']) === null) {
            Log::info('nfse.webhook.invalid_status', ['environment' => $credential->environment->value]);

            return response()->json(['ok' => true]);
        }

        ProcessNfseWebhookJob::dispatch($ref, $credential->environment->value, $this->payloadForQueue($payload));

        return response()->json(['ok' => true]);
    }

    /**
     * A credencial dona do token da URL. Compara as (no máximo duas) linhas SEM parar na primeira, em tempo constante:
     * o tempo da resposta não diz qual ambiente existe nem quanto do token bateu.
     */
    private function credentialFor(string $token): ?NfseCredential
    {
        $found = null;

        foreach (NfseCredential::query()->get() as $credential) {
            if (hash_equals((string) $credential->webhook_token, $token)) {
                $found = $credential;
            }
        }

        return $found;
    }

    /**
     * Corpo saneado (sem chave de segredo) para a fila; corpo gigante vira só os campos conhecidos.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function payloadForQueue(array $payload): array
    {
        $clean = NfsePayloadSanitizer::clean($payload);

        if (strlen((string) json_encode($clean)) <= self::MAX_PAYLOAD_BYTES) {
            return $clean;
        }

        return array_intersect_key($clean, array_flip(self::KNOWN_FIELDS));
    }
}
