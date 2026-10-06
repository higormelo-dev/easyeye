<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiPayloadEnricher, AiProviderSettings, AiRunExecutionService};
use App\Domains\AI\Support\AiJsonOutput;
use App\Enums\AI\{AiProvider, AiRiskLevel, AiRunMode, AiRunStatus};
use App\Models\Medicine;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sugestão de posologia padrão de um item do catálogo global por IA
 * (Manager → Medicamentos). Só PREENCHE o formulário: o admin revisa e
 * salva, e o médico ainda revisa no receituário.
 *
 * Mesmo caminho das demais chamadas de IA (prompt do servidor + preâmbulo de
 * segurança, guardrails, circuit breaker, custo em ai_run_provider_calls →
 * P&L), mas SÍNCRONO e como run da plataforma: na entity SaaS, sem carteira
 * de créditos de clínica — mesmo modelo do Manager\FinanceController::createPlatformRun.
 *
 * UMA chamada, na IA escolhida pelo admin (com uma só configurada, ela; com
 * mais de uma, o admin escolhe — o modo Validado chamaria duas em cadeia).
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
        return $this->providers() !== [];
    }

    /**
     * IAs que o admin pode escolher: todas com status "Configurado" no painel
     * de provedores (chave + modelo), Principal → Revisor → Árbitro primeiro.
     * NÃO depende dos papéis do assistente clínico: para escolher o Gemini não
     * pode ser preciso torná-lo Revisor (isso obrigaria as clínicas ao modo
     * Validado, com duas chamadas por atendimento). Em dev/testes (provedores
     * fake, sem chave) vale a lista habilitada.
     *
     * @return list<array{code: string, label: string, model: ?string}>
     */
    public function providers(): array
    {
        if (config('ai.provider_runtime') !== 'real') {
            $codes = $this->providerSettings->enabledCodes();
        } else {
            $roles = array_values(array_filter($this->providerSettings->roleAssignments()));
            $codes = array_values(array_filter(
                array_unique([...$roles, ...array_column(AiProvider::cases(), 'value')]),
                fn (string $code) => $this->providerSettings->isConfigured($code),
            ));
        }

        return array_map(fn (string $code) => [
            'code'  => $code,
            'label' => AiProvider::from($code)->label(),
            'model' => $this->providerSettings->model($code),
        ], $codes);
    }

    /**
     * Dados do item salvo que vão para a IA (inclui apresentação/classe da
     * CMED) — só catálogo: EAN, registro e laboratório não vão.
     *
     * @return array<string, mixed>
     */
    public function catalogContext(Medicine $model): array
    {
        return [
            'nome'               => $model->name,
            'principio_ativo'    => $model->active_ingredient,
            'concentracao'       => $model->concentration,
            'forma_farmaceutica' => $model->presentation?->name ?? $model->formLabel(),
            'apresentacao'       => $model->presentation_detail,
            'classe_terapeutica' => $model->therapeutic_class,
            'uso_oftalmico'      => (bool) $model->is_ophthalmic,
        ];
    }

    /**
     * IA da execução (ver chooseProvider) — o lote valida a escolha antes de
     * entrar na fila.
     *
     * @return array{code: string, label: string, model: ?string}
     */
    public function resolveProvider(?string $provider): array
    {
        return $this->chooseProvider($provider);
    }

    /**
     * @param array<string, mixed> $medicine dados do catálogo (nunca dado de paciente)
     * @param array<string, mixed> $metadata extra no registro da execução (ex.: id do lote)
     *
     * @return array{dosage: string, frequency: string, duration: string, instructions: string, note: string, provider: string, provider_label: string, ai_run_id: string}
     */
    public function suggest(array $medicine, string $saasEntityId, string $userId, ?string $provider = null, array $metadata = []): array
    {
        $chosen = $this->chooseProvider($provider);

        // O enricher valida o modo pela política do painel clínico (Validado
        // com 2+ IAs) e devolve prompt + guardrails; a execução aqui é UMA
        // chamada na IA escolhida pelo admin (run gravado como Economia), que
        // revisa antes de salvar — e o médico revisa de novo no receituário.
        $payload = $this->enricher->enrich([
            'workflow'    => self::WORKFLOW,
            'mode'        => ($this->providerSettings->availableModes()[0] ?? AiRunMode::Economy)->value,
            'risk_level'  => AiRiskLevel::Medium->value,
            'user_prompt' => __('ai.medicine_posology_user_prompt'),
            'context'     => ['medicamento' => array_filter($medicine, fn ($value) => $value !== null && $value !== '')],
        ], $saasEntityId, canConsensus: false);

        $run = AiRun::query()->create([
            'entity_id'    => $saasEntityId,
            'requested_by' => $userId,
            'workflow'     => $payload['workflow'],
            'mode'         => AiRunMode::Economy->value,
            'risk_level'   => $payload['risk_level'],
            // Pending com créditos 0: o AiRunExecutionService pula a carteira
            // inteira (ferramenta interna, não cobrada de clínica).
            'status'            => AiRunStatus::Pending->value,
            'estimated_credits' => 0,
            'reserved_credits'  => 0,
            'consumed_credits'  => 0,
            'input_summary'     => [
                'user_prompt'   => $payload['user_prompt'],
                'system_prompt' => $payload['system_prompt'] ?? null,
                'context'       => $payload['context'] ?? [],
                'expects_json'  => true,
                // Modelos de raciocínio (gpt-5*) gastam este limite PENSANDO
                // antes de escrever: com 600 o gpt-5-mini usava ~576 no
                // raciocínio e devolvia texto vazio (cobrado). Folga aqui não
                // encarece — só se paga o que o modelo usa.
                'max_output_tokens' => (int) config('medicines.posology_ai.max_output_tokens', 2000),
                'metadata'          => [
                    ...$metadata,
                    'source'          => $metadata['source'] ?? 'manager_medicines',
                    'pinned_provider' => $chosen['code'],
                    // Só catálogo (nunca paciente): libera provedor bloqueado
                    // para pacientes (ProviderDataPolicy) — ver AiOrchestrator.
                    'patient_data' => false,
                    'guardrails'   => $payload['_guardrails'] ?? [],
                ],
            ],
        ]);

        try {
            $this->execution->execute($run);
        } catch (Throwable $e) {
            // Detalhe técnico (provedor, HTTP) só no log; o run já foi marcado Failed.
            Log::warning('Sugestão de posologia por IA falhou', [
                'ai_run_id' => $run->id,
                'provider'  => $chosen['code'],
                'error'     => $e->getMessage(),
            ]);

            [$message, $transient] = $this->failureMessage($e, $chosen['label']);

            throw new MedicinePosologyAiException($message, $transient ? MedicinePosologyAiException::TRANSIENT : null, (string) $run->id);
        }

        $run->refresh();

        // Resultado entregue a quem pediu = aprovado (não fica pendente na fila de aprovação).
        if ($run->status === AiRunStatus::WaitingApproval) {
            $run->update(['status' => AiRunStatus::Approved->value, 'approved_by' => $userId, 'approved_at' => now()]);
        }

        if (trim((string) $run->final_output) === '') {
            throw new MedicinePosologyAiException(__('manager_medicines.ai_empty_output', ['provider' => $chosen['label']]), MedicinePosologyAiException::EMPTY_OUTPUT, (string) $run->id);
        }

        $suggestion = $this->normalize(AiJsonOutput::decode((string) $run->final_output) ?? []);

        if ($suggestion['dosage'] === '' && $suggestion['frequency'] === '') {
            throw new MedicinePosologyAiException($suggestion['note'] !== '' ? $suggestion['note'] : __('manager_medicines.ai_no_suggestion'), MedicinePosologyAiException::NO_SUGGESTION, (string) $run->id);
        }

        return [...$suggestion, 'provider' => $chosen['code'], 'provider_label' => $chosen['label'], 'ai_run_id' => (string) $run->id];
    }

    /**
     * Mensagem para o admin: QUAL IA falhou e o tipo (demora / sobrecarga do
     * provedor) — nunca o texto técnico do provedor. Com outra IA disponível,
     * sugere escolher outra. Demora/sobrecarga = transitório (o lote tenta de novo).
     *
     * @return array{0: string, 1: bool} mensagem e se o erro é transitório
     */
    private function failureMessage(Throwable $e, string $provider): array
    {
        $raw = strtolower($e->getMessage());

        $cause = match (true) {
            str_contains($raw, 'curl error 28') || str_contains($raw, 'timed out') || str_contains($raw, 'timeout')                         => 'ai_failed_timeout',
            (bool) preg_match('/\[(429|500|502|503|504)\]/', $raw) || str_contains($raw, 'high demand') || str_contains($raw, 'overloaded') => 'ai_failed_busy',
            default                                                                                                                         => 'ai_failed_provider',
        };

        $message = __('manager_medicines.' . $cause, ['provider' => $provider]);

        $message = count($this->providers()) > 1 ? $message . ' ' . __('manager_medicines.ai_try_other') : $message . ' ' . __('manager_medicines.ai_try_again');

        return [$message, $cause !== 'ai_failed_provider'];
    }

    /**
     * IA da execução: com uma configurada, ela (pedido sem escolha é aceito);
     * com mais de uma, a escolhida — que precisa estar entre as disponíveis.
     *
     * @return array{code: string, label: string, model: ?string}
     */
    private function chooseProvider(?string $provider): array
    {
        $providers = $this->providers();

        if ($providers === []) {
            throw new MedicinePosologyAiException(__('manager_medicines.ai_unavailable'));
        }

        if (blank($provider)) {
            if (count($providers) > 1) {
                // Tela achava que só havia uma IA: a lista mudou com a página aberta.
                throw new MedicinePosologyAiException(__('manager_medicines.ai_choose_provider'), MedicinePosologyAiException::STALE_PROVIDERS);
            }

            return $providers[0];
        }

        foreach ($providers as $candidate) {
            if ($candidate['code'] === $provider) {
                return $candidate;
            }
        }

        throw new MedicinePosologyAiException(__('manager_medicines.ai_provider_invalid'), MedicinePosologyAiException::STALE_PROVIDERS);
    }

    /**
     * Saída do modelo é dado não confiável: só texto plano, cortado no
     * tamanho das colunas.
     *
     * @param array<string, mixed> $data
     *
     * @return array{dosage: string, frequency: string, duration: string, instructions: string, note: string}
     */
    public function normalize(array $data): array
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
