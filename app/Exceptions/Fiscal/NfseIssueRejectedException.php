<?php

declare(strict_types=1);

namespace App\Exceptions\Fiscal;

use RuntimeException;

/**
 * Uma guarda barrou a ação — emissão (a nota NÃO foi criada) ou cancelamento (a nota não mudou) — e nada foi enviado à Focus. `reason` é a chave traduzida em
 * `fiscal.errors.*`; a mensagem já vem traduzida para a tela (422) e `context` leva o detalhe para o log (campos que
 * faltam etc.). Na emissão automática vira log; na manual, resposta 422 com o motivo.
 */
final class NfseIssueRejectedException extends RuntimeException
{
    public const INVOICE_NOT_PAID = 'fatura_nao_paga';

    public const INVOICE_REFUNDED = 'fatura_estornada';

    public const ZERO_AMOUNT = 'valor_zero';

    public const INVALID_DOCUMENT = 'documento_invalido';

    public const ADDRESS_REQUIRED = 'endereco_obrigatorio';

    public const IBGE_MISSING = 'ibge_ausente';

    public const CREDENTIAL_MISSING = 'credencial_ausente';

    public const DOCUMENT_EXISTS = 'document_exists';

    public const ISSUER_INCOMPLETE = 'prestador_incompleto';

    // Cancelamento (NfseCancellationService): a ação foi barrada antes de qualquer chamada à Focus.
    public const NOT_AUTHORIZED = 'nota_nao_autorizada';

    public const INVALID_JUSTIFICATION = 'justificativa_invalida';

    public const NO_CANCELLATION = 'sem_cancelamento';

    /** @param array<string, mixed> $context */
    private function __construct(public readonly string $reason, string $message, public readonly array $context = [])
    {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $context */
    public static function because(string $reason, array $context = []): self
    {
        // `fields` (prestador incompleto) entra na frase; o resto só no contexto do log.
        $replace = isset($context['fields']) ? ['fields' => implode(', ', (array) $context['fields'])] : [];

        return new self($reason, __("fiscal.errors.{$reason}", $replace), $context);
    }
}
