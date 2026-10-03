<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiPayloadEnricher, AiProviderSettings, AiRunExecutionService};
use App\Domains\AI\Support\AiJsonOutput;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sugestão de posologia padrão de um item do catálogo global por IA
 * (Manager → Medicamentos). Só PREENCHE o formulário: o admin revisa e
 * salva, e o médico ainda revisa no receituário.
 *
 * Mesmo caminho das demais chamadas de IA (prompt do servidor + preâmbulo de
 * segurança, guardrails, fallback/circuit breaker, custo em
 * ai_run_provider_calls → P&L), mas SÍNCRONO e como run da plataforma: na
 * entity SaaS, sem carteira de créditos de clínica — mesmo modelo do
 * Manager\FinanceController::createPlatformRun.
 */
class MedicinePosologyAiService
{
    public const WORKFLOW = 'medicine_posology';

    /** Limites das colunas (MedicineRequest). */
    private const LIMITS = ['dosage' => 255, 'frequency' => 255, 'duration' => 255, 'instructions' => 2000];

    public function __construct(
        private readonly AiPayloadEnricher $enricher,
        private readonly AiRunExecutionService $execution,
        private readonly AiProviderSettings $providerSettings,
    ) {
    }

    /** Há provedor de IA utilizável (com credencial e modelo) neste ambiente? */
    public function available(): bool
    {
        if (config('ai.provider_runtime') !== 'real') {
            return true; // provedores fake (dev/testes) não precisam de credencial
        }

        foreach ($this->providerSettings->enabledCodes() as $code) {
            if ($this->providerSettings->isConfigured($code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $medicine dados do catálogo (nunca dado de paciente)
     *
     * @return array{dosage: string, frequency: string, duration: string, instructions: string, note: string}
     */
    public function suggest(array $medicine, string $saasEntityId, string $userId): array
    {
        if (! $this->available()) {
            throw new MedicinePosologyAiException(__('manager_medicines.ai_unavailable'));
        }

        // Modo: o piso que a configuração de provedores exige (Validado com 2+).
        $modes = $this->providerSettings->availableModes();

        $payload = $this->enricher->enrich([
            'workflow'    => self::WORKFLOW,
            'mode'        => ($modes[0] ?? AiRunMode::Economy)->value,
            'risk_level'  => AiRiskLevel::Medium->value,
            'user_prompt' => __('ai.medicine_posology_user_prompt'),
            'context'     => ['medicamento' => array_filter($medicine, fn ($value) => $value !== null && $value !== '')],
        ], $saasEntityId, canConsensus: false);

        $run = AiRun::query()->create([
            'entity_id'    => $saasEntityId,
            'requested_by' => $userId,
            'workflow'     => $payload['workflow'],
            'mode'         => $payload['mode'],
            'risk_level'   => $payload['risk_level'],
            // Pending com créditos 0: o AiRunExecutionService pula a carteira
            // inteira (ferramenta interna, não cobrada de clínica).
            'status'            => AiRunStatus::Pending->value,
            'estimated_credits' => 0,
            'reserved_credits'  => 0,
            'consumed_credits'  => 0,
            'input_summary'     => [
                'user_prompt'       => $payload['user_prompt'],
                'system_prompt'     => $payload['system_prompt'] ?? null,
                'context'           => $payload['context'] ?? [],
                'expects_json'      => true,
                'max_output_tokens' => 600,
                'metadata'          => [
                    'source'     => 'manager_medicines',
                    'guardrails' => $payload['_guardrails'] ?? [],
                ],
            ],
        ]);

        try {
            $this->execution->execute($run);
        } catch (Throwable $e) {
            // Detalhe técnico (provedor, HTTP) só no log; o run já foi marcado Failed.
            Log::warning('Sugestão de posologia por IA falhou', ['ai_run_id' => $run->id, 'error' => $e->getMessage()]);

            throw new MedicinePosologyAiException(__('manager_medicines.ai_failed'));
        }

        $run->refresh();

        // Resultado entregue a quem pediu = aprovado (não fica pendente na fila de aprovação).
        if ($run->status === AiRunStatus::WaitingApproval) {
            $run->update(['status' => AiRunStatus::Approved->value, 'approved_by' => $userId, 'approved_at' => now()]);
        }

        $suggestion = $this->normalize(AiJsonOutput::decode((string) $run->final_output) ?? []);

        if ($suggestion['dosage'] === '' && $suggestion['frequency'] === '') {
            throw new MedicinePosologyAiException($suggestion['note'] !== '' ? $suggestion['note'] : __('manager_medicines.ai_no_suggestion'));
        }

        return $suggestion;
    }

    /**
     * Saída do modelo é dado não confiável: só texto plano, cortado no
     * tamanho das colunas.
     *
     * @param array<string, mixed> $data
     *
     * @return array{dosage: string, frequency: string, duration: string, instructions: string, note: string}
     */
    private function normalize(array $data): array
    {
        $text = static function (mixed $value, int $max): string {
            $value = is_scalar($value) ? (string) $value : '';

            // Espaços repetidos colapsam; quebras de linha (orientações) ficam.
            return mb_substr(trim(preg_replace('/[^\S\n]+/u', ' ', strip_tags($value)) ?? ''), 0, $max);
        };

        $suggestion = [];

        foreach (self::LIMITS as $field => $max) {
            $suggestion[$field] = $text($data[$field] ?? null, $max);
        }

        $suggestion['note'] = $text($data['note'] ?? null, 500);

        return $suggestion;
    }
}
