<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use RuntimeException;

/**
 * Falha esperada da importação do catálogo (arquivo errado, cabeçalho não
 * encontrado...) — a mensagem é traduzida e pode ser mostrada ao admin.
 * Qualquer outra exceção vira mensagem genérica (nunca SQL/stack na tela).
 */
class MedicineImportException extends RuntimeException
{
}
