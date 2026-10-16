<?php

declare(strict_types=1);

namespace App\Domains\AI\Services;

use App\Domains\AI\Exceptions\SpeechTranscriptionException;
use App\Domains\AI\Support\{ProviderDataPolicy, WavInspector};
use App\Enums\AI\AiProvider;
use Illuminate\Http\Client\{ConnectionException, PendingRequest};
use Illuminate\Support\Facades\{Http, Log};

/**
 * Ditado por voz do laudo (Fase 5): transcreve um WAV curto no provedor de
 * IA configurado em `ai.transcription`.
 *
 * O áudio é dado de saúde (o médico dita os achados do exame), então:
 *  - NUNCA passa pelo reconhecimento de voz do navegador (manda o áudio ao
 *    Google, sem contrato) — sempre por aqui, por provedor da política LGPD;
 *  - só entra provedor HABILITADO no Manager (AiProviderSettings — a fonte de
 *    verdade; a chave no .env sozinha não habilita nada: retirar o provedor no
 *    Manager corta o envio do áudio na hora), que aceita dado de paciente
 *    (ProviderDataPolicy) e, se processa fora do Brasil sem adequação, só com
 *    o mecanismo de transferência registrado — a mesma regra dos papéis do
 *    assistente;
 *  - o áudio não é gravado em disco nem em banco: lê-se do arquivo temporário
 *    do upload, vai ao provedor e acaba. O texto devolvido também não é
 *    guardado nem logado aqui — vira conteúdo do laudo na tela do médico.
 *
 * Fala o protocolo de transcrição "compatível com OpenAI"
 * (POST {base_url}/audio/transcriptions, multipart), igual em Mistral
 * (Voxtral), OpenAI, Groq e Azure OpenAI.
 */
class SpeechTranscriptionService
{
    /** Provedores com o endpoint de transcrição compatível (Anthropic/Gemini/xAI/Maritaca não). */
    public const PROVIDERS = [AiProvider::Mistral, AiProvider::AzureOpenAI, AiProvider::OpenAI, AiProvider::Groq];

    /** Duração mínima (s) — abaixo disso é toque no botão, não ditado. */
    public const MIN_SECONDS = 0.4;

    /** Teto do texto devolvido: ditado de 2 min não chega perto; acima disso é resposta anômala. */
    private const MAX_TEXT_CHARS = 20000;

    public function __construct(private readonly AiProviderSettings $settings)
    {
    }

    /**
     * Provedor e modelo que atendem o ditado agora, ou null se o recurso está
     * desligado, mal configurado ou não cumpre a política LGPD.
     *
     * @return array{provider: AiProvider, model: string}|null
     */
    public function resolve(): ?array
    {
        if (! config('ai.transcription.enabled')) {
            return null;
        }

        $provider = AiProvider::tryFrom((string) config('ai.transcription.provider'));
        $model    = trim((string) config('ai.transcription.model'));

        if ($provider === null || ! in_array($provider, self::PROVIDERS, true) || $model === '') {
            return null;
        }

        $code = $provider->value;

        // Lido a cada chamada (resolve() roda em toda transcrição, logo antes do
        // envio): o setting é invalidado no Manager e vale na requisição seguinte.
        if (! in_array($code, $this->settings->enabledCodes(), true)) {
            return null;
        }

        if (blank(config("services.{$code}.api_key")) || blank(config("ai.providers.{$code}.base_url"))) {
            return null;
        }

        if (! ProviderDataPolicy::allowsPatientData($provider)) {
            return null;
        }

        if (ProviderDataPolicy::requiresTransferRecord($provider, $model)
            && ! array_key_exists($code, $this->settings->transferRecords())) {
            return null;
        }

        return ['provider' => $provider, 'model' => $model];
    }

    public function isAvailable(): bool
    {
        return $this->resolve() !== null;
    }

    /**
     * Confere o arquivo (WAV PCM, duração dentro do teto) antes de gastar a
     * chamada. O cliente não é confiável: o teto de duração é o teto de custo.
     *
     * @return float duração em segundos
     */
    public function inspect(string $path): float
    {
        $seconds = WavInspector::durationSeconds($path);

        if ($seconds === null || $seconds < self::MIN_SECONDS) {
            throw new SpeechTranscriptionException(SpeechTranscriptionException::INVALID_AUDIO);
        }

        $max = (int) config('ai.transcription.max_seconds', 120);

        if ($seconds > $max) {
            throw new SpeechTranscriptionException(SpeechTranscriptionException::TOO_LONG, ['seconds' => $max]);
        }

        return $seconds;
    }

    /**
     * `$beforeSend` roda com o destino e a duração DEPOIS de tudo conferido e ANTES de o
     * áudio sair: é onde o chamador grava a trilha. Se lançar, nada é enviado (falha fechada).
     *
     * @param (callable(array{provider: AiProvider, model: string, seconds: float}): void)|null $beforeSend
     *
     * @return array{text: string, provider: AiProvider, model: string, seconds: float}
     */
    public function transcribe(string $path, ?callable $beforeSend = null): array
    {
        $resolved = $this->resolve() ?? throw new SpeechTranscriptionException(SpeechTranscriptionException::UNAVAILABLE);

        $seconds = $this->inspect($path);

        if ($beforeSend !== null) {
            $beforeSend(['provider' => $resolved['provider'], 'model' => $resolved['model'], 'seconds' => $seconds]);
        }

        $provider = $resolved['provider'];
        $code     = $provider->value;
        $handle   = fopen($path, 'rb');

        if ($handle === false) {
            throw new SpeechTranscriptionException(SpeechTranscriptionException::INVALID_AUDIO);
        }

        try {
            $response = $this->authenticate(Http::baseUrl(rtrim((string) config("ai.providers.{$code}.base_url"), '/')), $provider)
                ->withOptions(config('ai.http_force_ipv4') ? ['force_ip_resolve' => 'v4'] : [])
                ->withUserAgent((string) config('ai.user_agent', 'EasyEye/1.0'))
                ->connectTimeout(5)
                ->timeout(max(5, (int) config('ai.transcription.timeout_seconds', 60)))
                ->attach('file', $handle, 'dictation.wav', ['Content-Type' => 'audio/wav'])
                ->post('/audio/transcriptions', [
                    'model'           => $resolved['model'],
                    'language'        => (string) config('ai.transcription.language', 'pt'),
                    'response_format' => 'json',
                    'temperature'     => 0,
                ]);
        } catch (ConnectionException) {
            $this->logFailure($provider, null);

            throw new SpeechTranscriptionException(SpeechTranscriptionException::PROVIDER_FAILED);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $response->successful()) {
            $this->logFailure($provider, $response->status());

            throw new SpeechTranscriptionException(SpeechTranscriptionException::PROVIDER_FAILED);
        }

        $text = $this->cleanText((string) data_get($response->json(), 'text', ''));

        if ($text === '') {
            throw new SpeechTranscriptionException(SpeechTranscriptionException::NO_SPEECH);
        }

        return ['text' => $text, 'provider' => $provider, 'model' => $resolved['model'], 'seconds' => $seconds];
    }

    private function authenticate(PendingRequest $request, AiProvider $provider): PendingRequest
    {
        $key = trim((string) config("services.{$provider->value}.api_key"));

        return config("ai.providers.{$provider->value}.auth") === 'api-key'
            ? $request->withHeaders(['api-key' => $key])
            : $request->withToken($key);
    }

    /** Texto puro: sem caracteres de controle (menos quebra de linha) e com teto de tamanho. */
    private function cleanText(string $text): string
    {
        $text = preg_replace('/[^\P{C}\n]+/u', '', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';

        return mb_substr(trim($text), 0, self::MAX_TEXT_CHARS);
    }

    /** Só provedor e status — nunca o corpo da resposta nem o áudio (podem ecoar dado do paciente). */
    private function logFailure(AiProvider $provider, ?int $status): void
    {
        Log::warning('speech.transcription.failed', ['provider' => $provider->value, 'status' => $status]);
    }
}
