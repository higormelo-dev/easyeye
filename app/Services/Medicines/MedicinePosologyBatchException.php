<?php

declare(strict_types=1);

namespace App\Services\Medicines;

use RuntimeException;

/** Lote de posologia por IA recusado (mensagem já traduzida para a tela). */
class MedicinePosologyBatchException extends RuntimeException
{
    public static function running(): self
    {
        return new self(__('manager_medicines.batch_in_progress'));
    }

    public static function nothingToDo(): self
    {
        return new self(__('manager_medicines.batch_nothing_to_do'));
    }
}
