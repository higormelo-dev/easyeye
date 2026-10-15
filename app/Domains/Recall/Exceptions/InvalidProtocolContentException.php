<?php

declare(strict_types=1);

namespace App\Domains\Recall\Exceptions;

use RuntimeException;

/**
 * O conteúdo de um protocolo de retorno não passou no esquema (ProtocolContentSchema).
 * Carrega TODOS os erros achados, por caminho (`tasks.0.interval.min`), para a tela
 * mostrar cada um no seu campo.
 */
final class InvalidProtocolContentException extends RuntimeException
{
    /** Caminho usado quando o erro é do conteúdo inteiro. */
    public const ROOT = 'content';

    /**
     * @param array<string, array{0: string, 1: array<string, mixed>}> $errors caminho → [código, parâmetros]
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Conteúdo de protocolo inválido: ' . implode(', ', array_map(
            static fn (string $path, array $error): string => $path . '=' . $error[0],
            array_keys($errors),
            $errors,
        )));
    }

    /** @return array<string, string> caminho → código do erro */
    public function codes(): array
    {
        return array_map(static fn (array $error): string => $error[0], $this->errors);
    }

    /** @return array<string, string> caminho → mensagem traduzida */
    public function messages(): array
    {
        return array_map(
            static fn (array $error): string => __('recall_protocol.errors.' . $error[0], $error[1]),
            $this->errors,
        );
    }
}
