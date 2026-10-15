<?php

declare(strict_types=1);

namespace App\Services\Surgery\Guards;

use App\Models\Doctor;
use App\Models\Surgery\SurgicalCase;

/**
 * Condição para registrar a cirurgia como REALIZADA. Cada etapa do módulo
 * registra a sua em `config('surgery.perform_guards')` (ex.: checklist de
 * cirurgia segura com a Pausa Cirúrgica fechada; autorização ainda válida).
 *
 * Roda DENTRO da transação de `SurgeryPerformService::perform()`, com a linha
 * do caso já travada e relida: valide o estado ATUAL (`$locked`), nunca uma
 * instância carregada antes. Recusar = lançar `SurgeryRuleException`; nada é
 * gravado. `$input` é o que o cirurgião enviou (inclui as justificativas de
 * exceção, ex.: `time_out_override_reason`).
 */
interface SurgeryPerformGuard
{
    /** @param array<string, mixed> $input */
    public function assert(SurgicalCase $locked, array $input, Doctor $performer): void;
}
