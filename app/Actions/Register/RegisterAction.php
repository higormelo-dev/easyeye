<?php

namespace App\Actions\Register;

use App\Services\Auth\PhoneVerificationService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Throwable;

class RegisterAction
{
    public function __construct(
        private readonly CreateUserAction $createUser,
        private readonly CreateEntityAction $createEntity,
        private readonly CreateEntityUserAction $createEntityUser,
        private readonly StartTrialAction $startTrial,
    ) {
    }

    /**
     * Executa o registro completo dentro de uma transação.
     *
     * Ordem de operações:
     *  1. Cria User
     *  2. Cria Entity (observer suprimido para evitar trial duplicado)
     *  3. Cria EntityUser (user = admin da empresa)
     *  4. Inicia trial com o plano escolhido
     *
     * Jobs de e-mail são disparados FORA da transação para não bloqueá-la.
     *
     * @return array{user, entity, entityUser, subscription}
     */
    public function execute(array $data): array
    {
        $result = DB::transaction(function () use ($data) {
            $user         = $this->createUser->execute($data);
            $entity       = $this->createEntity->execute($data);
            $entityUser   = $this->createEntityUser->execute($user, $entity);
            $subscription = $this->startTrial->execute($entity, $data['plan_id'] ?? null);

            return compact('user', 'entity', 'entityUser', 'subscription');
        });

        // E-mails dispatched após commit para não travar a transação
        event(new Registered($result['user']));

        // Código de verificação do WhatsApp do responsável — segundo canal de
        // contato confirmado (o time comercial usa o número validado para
        // finalizar a venda do plano). Nunca pode quebrar o registro:
        // indisponibilidade da instância Z-API só loga e o usuário reenvia
        // depois pelo banner do painel.
        try {
            app(PhoneVerificationService::class)->sendCode($result['user']);
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }
}
