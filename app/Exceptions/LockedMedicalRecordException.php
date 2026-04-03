<?php

declare(strict_types = 1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Lançada ao tentar modificar ou excluir um prontuário assinado.
 * CFM Res. 2.227/2018 — prontuário eletrônico é imutável após assinatura.
 */
class LockedMedicalRecordException extends RuntimeException
{
}
