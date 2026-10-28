<?php

namespace App\Actions\Register;

use App\Models\User;
use App\Services\Auth\PhoneVerificationService;
use App\Services\TermsService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class RegisterAction
{
    public function __construct(
        private readonly CreateUserAction $createUser,
        private readonly CreateEntityAction $createEntity,
        private readonly CreateEntityUserAction $createEntityUser,
        private readonly StartTrialAction $startTrial,
        private readonly TermsService $terms,
    ) {
    }

    /**
     * Executa o registro completo dentro de uma transação.
     *
     * Ordem de operações:
     *  1. Cria User
     *  2. Cria Entity (observer suprimido para evitar trial duplicado)
     *  3. Cria EntityUser (user = admin da empresa)
     *  4. Inicia trial com o plano escolhido (start_mode=checkout: sem trial)
     *  5. Confere os termos e grava o aceite dos documentos vigentes, na MESMA transação — ônus da prova do
     *     consentimento (LGPD art. 8º, §5º): ou nasce tudo, ou nada. A conferência roda SEMPRE (ver acceptTerms).
     *
     * Jobs de e-mail são disparados FORA da transação para não bloqueá-la.
     *
     * @return array{User, entity, entityUser, subscription}
     */
    public function execute(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            $user       = $this->createUser->execute($data);
            $entity     = $this->createEntity->execute($data);
            $entityUser = $this->createEntityUser->execute($user, $entity);
            // "Contratar já pagando": sem trial — a assinatura nasce no
            // checkout (/signup-checkout/contract) com a 1ª cobrança.
            $subscription = ($data['start_mode'] ?? 'trial') === 'checkout'
                ? null
                : $this->startTrial->execute($entity, $data['plan_id'] ?? null, $data['billing_cycle'] ?? null);

            $this->acceptTerms($user, (array) ($data['terms_version_ids'] ?? []), $data['terms_accepted'] ?? false);

            return compact('user', 'entity', 'entityUser', 'subscription');
        });

        // E-mails dispatched após commit para não travar a transação
        event(new Registered($result['user']));

        // Código de verificação do WhatsApp do responsável — segundo canal de
        // contato confirmado (o time comercial usa o número validado para
        // finalizar a venda do plano). Nunca pode quebrar o registro:
        // indisponibilidade do WhatsApp (app global) só loga e o usuário reenvia
        // depois pelo banner do painel.
        try {
            app(PhoneVerificationService::class)->sendCode($result['user']);
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }

    /**
     * Confere os termos DENTRO da transação (a validação do request é anterior e a publicação de uma versão não
     * é atômica com a leitura) e grava o aceite. A conferência roda SEMPRE, também sem versão vigente e também com
     * terms_accepted falso: o conjunto de versões exibido (terms_version_ids, possivelmente vazio) tem de ser
     * EXATAMENTE o vigente agora. Qualquer diferença — inclusive de/para vazio ([v1]→[] quando a versão é
     * trocada, []→[v1] quando uma surge depois do request) — é 422 terms_changed e a transação desfaz usuário,
     * clínica e aceites. Com versão vigente, exige terms_accepted verdadeiro. Grava só as versões exibidas que
     * são as vigentes.
     *
     * @param array<int, mixed> $shownIds
     */
    private function acceptTerms(User $user, array $shownIds, mixed $accepted): void
    {
        $current = $this->terms->currentRequired();

        if (! TermsService::sameVersionSet($shownIds, $current)) {
            throw ValidationException::withMessages(['terms_accepted' => __('auth.register.terms_changed')]);
        }

        if ($current === []) {
            return;
        }

        if (filter_var($accepted, FILTER_VALIDATE_BOOLEAN) !== true) {
            throw ValidationException::withMessages(['terms_accepted' => __('auth.register.terms_required')]);
        }

        $shown = array_map(fn ($id) => strtolower((string) $id), $shownIds);

        foreach ($current as $version) {
            if (in_array(strtolower((string) $version->id), $shown, true)) {
                $this->terms->accept($user, $version);
            }
        }
    }
}
