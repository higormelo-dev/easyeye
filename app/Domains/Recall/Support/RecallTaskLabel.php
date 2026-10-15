<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use App\Domains\Recall\Enums\RecallExamKey;

/**
 * "O que falta" de um retorno, em linguagem de recepção: "Retorno pedido pelo médico", "Consulta de retorno",
 * "Exame: Campo visual". Nunca diz a condição clínica (a minimização por perfil é de quem monta a tela).
 */
final class RecallTaskLabel
{
    public static function of(string $source, string $kind, ?string $examKey = null): string
    {
        if ($source === 'doctor_order') {
            return __('recall.task.doctor_order');
        }

        if ($source === 'course') {
            return __('recall.task.course');
        }

        if ($kind === 'exam') {
            $exam = $examKey !== null ? RecallExamKey::tryFrom($examKey) : null;

            return __('recall.task.exam', ['exam' => $exam?->label() ?? (string) $examKey]);
        }

        return __('recall.task.' . ($kind === 'consultation' ? 'consultation' : 'other'));
    }
}
