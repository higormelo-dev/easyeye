<?php

declare(strict_types=1);

namespace App\Exceptions\Surgery;

use RuntimeException;

/**
 * Sinal INTERNO do agendamento cirúrgico: depois de travar a linha do caso, o
 * conjunto de recursos a travar (equipe, paciente) cresceu em relação ao que
 * os advisory locks já seguravam. A tentativa é desfeita (SAVEPOINT solta os
 * locks) e refeita com o conjunto certo, sempre em ordem crescente de chave.
 * Nunca chega ao usuário: depois de algumas tentativas vira SurgeryRuleException.
 */
final class SurgeryLockSetChanged extends RuntimeException
{
}
