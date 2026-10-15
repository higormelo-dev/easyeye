<?php

declare(strict_types=1);

/**
 * Processo FILHO do RecallInviteConcurrencyTest: "outra requisição" do convite de retorno por WhatsApp numa sessão
 * PostgreSQL própria. Faz a operação DENTRO de uma transação, avisa o principal pelo arquivo marcador e SEGURA a
 * transação por alguns segundos antes de comitar — a janela em que o principal tenta a operação concorrente. As travas
 * (clínica, paciente, linha do retorno) ficam seguras até o commit, porque a transação do próprio serviço aninha na
 * deste processo.
 *
 * Uso: php recall_invite_child.php <modo> <id> <arquivo_marcador> <segundos_de_pausa>
 *   modos (id = retorno): invite · dismiss
 *         (id = clínica): run
 * Em caso de sucesso imprime "OK".
 */

use App\Domains\Recall\Services\{RecallActionService, RecallInviteService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $id, $marker, $holdSeconds] = $argv;

try {
    DB::transaction(function () use ($mode, $id, $marker, $holdSeconds): void {
        $entityId = (string) getenv('RIC_ENTITY');

        switch ($mode) {
            case 'invite':
                app(RecallInviteService::class)->inviteManual($entityId, $id, null);

                break;
            case 'run':
                app(RecallInviteService::class)->runClinic($id);

                break;
            case 'dismiss':
                app(RecallActionService::class)->dismiss($entityId, $id, 'Dispensado pelo processo filho do teste.');

                break;
            default:
                throw new InvalidArgumentException("modo desconhecido: {$mode}");
        }

        file_put_contents($marker, 'holding');
        usleep((int) ((float) $holdSeconds * 1_000_000));
    });

    fwrite(STDOUT, "OK\n");
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
