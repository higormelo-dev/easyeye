<?php

namespace App\Support\Billing;

use App\Exceptions\Billing\CheckoutException;
use App\Models\Entity;
use App\Rules\CpfCnpj;

/**
 * CPF/CNPJ do pagador (a clínica, em `entities.national_registration`). O Asaas cria o cliente sem documento, mas recusa
 * a ASSINATURA/cobrança dele (POST /v3/subscriptions → 400 invalid_object, "Para criar esta cobrança é necessário
 * preencher o CPF ou CNPJ do cliente."), e sem esta conferência o usuário via um 502 genérico (`gateway_error`).
 *
 * `assertFor()` roda ANTES de qualquer chamada ao gateway nos caminhos em que a assinatura nasce com o NOSSO cliente
 * (checkout transparente e troca de plano do CheckoutService, criação pelo Manager) e lança `document_required` (422)
 * com o motivo. Documento presente mas com dígito verificador errado (cadastro antigo, sem validação) também barra: o
 * gateway o recusaria do mesmo jeito (`invalid_cpfCnpj`/`invalid_object`).
 *
 * Fora daqui, de propósito: a abertura do Checkout hospedado de uma fatura já existente
 * (HostedCheckoutService::open), que refaz a chamada sem o cliente e deixa a página do Asaas coletar o documento.
 */
final class PayerDocument
{
    public static function isUsable(Entity $entity): bool
    {
        return CpfCnpj::isValid($entity->national_registration);
    }

    /** @throws CheckoutException `document_required` (422) quando a clínica não tem CPF/CNPJ válido no cadastro */
    public static function assertFor(Entity $entity): void
    {
        if (! self::isUsable($entity)) {
            throw CheckoutException::make('document_required');
        }
    }
}
