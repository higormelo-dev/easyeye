<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Name of the controller for messages.
     */
    protected string $titleController;

    /**
     * Resolve o tamanho da página para endpoints paginados de forma segura.
     *
     * Lê `per_page` da request com default e teto configuráveis. Substitui o
     * antigo `min((int) per_page, 10)`, que travava qualquer cliente em 10
     * itens por página (sincronizações grandes ficavam lentas).
     */
    protected function perPage(int $default = 25, int $max = 100): int
    {
        $value = (int) request()->integer('per_page', $default);

        if ($value < 1) {
            $value = $default;
        }

        return min($value, $max);
    }

    /**
     * Get update message based on request type.
     */
    protected function getUpdateMessage(Request $request): string
    {
        if ($request->has('type_method')) {
            return $this->titleController .
                ($request->active ? ' desbloqueado(a) ' : ' bloqueado(a) ') . ' com sucesso.';
        }

        return $this->titleController . ' alterado(a) com sucesso.';
    }

    /**
     * Get create success message.
     */
    protected function getCreateMessage(): string
    {
        return $this->titleController . ' cadastrado(a) com sucesso.';
    }

    /**
     * Get delete success message.
     */
    protected function getDeleteMessage(): string
    {
        return $this->titleController . ' deletado(a) com sucesso.';
    }

    /**
     * Get restore success message.
     */
    protected function getRestoreMessage(): string
    {
        return $this->titleController . ' restaurado(a) com sucesso.';
    }
}
