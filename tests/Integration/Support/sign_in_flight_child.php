<?php

declare(strict_types=1);

/**
 * Processo FILHO do MedicalRecordClinicalChildrenConcurrencyTest: assina o
 * prontuário de verdade (Signable::sign), numa sessão PostgreSQL própria, e
 * SEGURA a trava da linha do prontuário por alguns segundos logo depois de
 * tomá-la — a janela em que o processo principal tenta gravar uma evolução ou
 * um procedimento no MESMO prontuário. Terminado o intervalo, o sign() segue e
 * comita.
 *
 * Uso: php sign_in_flight_child.php <medical_record_id> <entity_user_id> <arquivo_marcador> <segundos_de_pausa>
 */

use App\Models\{EntityUser, MedicalRecord};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $recordId, $entityUserId, $marker, $holdSeconds] = $argv;

$held = false;

// A 1ª trava FOR UPDATE em medical_records é a do sign() (whileUnlocked).
// Logo depois, avisa o principal e fica segurando.
DB::listen(function (QueryExecuted $query) use (&$held, $marker, $holdSeconds): void {
    $sql = strtolower($query->sql);

    if (! $held && str_contains($sql, 'for update') && str_contains($sql, 'medical_records')) {
        $held = true;
        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    }
});

try {
    MedicalRecord::query()->withoutGlobalScopes()->findOrFail($recordId)
        ->sign(EntityUser::query()->withoutGlobalScopes()->findOrFail($entityUserId));

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
