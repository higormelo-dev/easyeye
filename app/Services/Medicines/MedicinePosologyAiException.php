<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use RuntimeException;

/** Falha da sugestão de posologia por IA com mensagem já traduzida para a tela. */
class MedicinePosologyAiException extends RuntimeException
{
    /** Lista de IAs da tela desatualizada (provedores mudaram com a página aberta). */
    public const STALE_PROVIDERS = 'stale_providers';

    /** Provedor demorou ou está sobrecarregado (vale tentar de novo). */
    public const TRANSIENT = 'transient';

    /** A IA respondeu, mas sem posologia utilizável (sem dose/frequência ou texto inválido). */
    public const NO_SUGGESTION = 'no_suggestion';

    /**
     * A IA não devolveu texto nenhum — modelo de raciocínio que esgotou o
     * limite de saída pensando (ex.: gpt-5-mini). Não é "não sabe": não
     * confundir com NO_SUGGESTION.
     */
    public const EMPTY_OUTPUT = 'empty_output';

    /**
     * @param ?string $aiRunId execução de IA registrada (custo real no lote)
     */
    public function __construct(string $message, public readonly ?string $reason = null, public readonly ?string $aiRunId = null)
    {
        parent::__construct($message);
    }
}
