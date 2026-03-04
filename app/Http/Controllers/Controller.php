<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Name of the controller for messages
     */
    protected string $titleController;

    /**
     * Get update message based on request type
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
     * Get create success message
     */
    protected function getCreateMessage(): string
    {
        return $this->titleController . ' cadastrado(a) com sucesso.';
    }

    /**
     * Get delete success message
     */
    protected function getDeleteMessage(): string
    {
        return $this->titleController . ' deletado(a) com sucesso.';
    }

    /**
     * Get restore success message
     */
    protected function getRestoreMessage(): string
    {
        return $this->titleController . ' restaurado(a) com sucesso.';
    }
}
