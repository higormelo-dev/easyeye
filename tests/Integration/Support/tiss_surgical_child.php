<?php

declare(strict_types=1);

/**
 * Processo FILHO do TissSurgicalSendRaceTest: "outra requisição" do módulo cirúrgico numa
 * sessão PostgreSQL própria.
 *
 *  - send-slow:     envia a guia por SendSurgicalGuideService com um transporte LENTO — avisa o
 *                   principal (arquivo marcador) DENTRO da chamada de rede (a transmissão já
 *                   está 'sending', comitada) e segura por alguns segundos;
 *  - generate-hold: gera a solicitação de internação do caso e SEGURA a transação (a trava do
 *                   caso na mão) antes de comitar.
 *
 * Uso: php tiss_surgical_child.php <modo> <id_da_guia_ou_do_caso> <arquivo_marcador> <segundos>
 */

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\{TissEntityOperatorCredential, TissGuide};
use App\Domains\Tiss\Services\Transport\{TissMessageTransportContract, TissTransportContract};
use App\Domains\Tiss\Surgical\{SendSurgicalGuideService, SurgicalGuideService};
use App\Models\Surgery\SurgicalCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $mode, $id, $marker, $holdSeconds] = $argv;

try {
    if ($mode === 'send-slow') {
        $transport = new class($marker, (float) $holdSeconds) implements TissMessageTransportContract, TissTransportContract {
            public function __construct(private readonly string $marker, private readonly float $hold)
            {
            }

            private function accepted(): array
            {
                return ['ok' => true, 'status_code' => 200, 'response_body' => '<ansTISS><x/></ansTISS>', 'protocol_number' => 'PRT-CHILD', 'protocol_status' => 'accepted', 'error_code' => null, 'error_message' => null, 'response_time_ms' => 1];
            }

            public function sendMessage(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, string $transactionType, array $context = []): array
            {
                file_put_contents($this->marker, 'holding');
                usleep((int) ($this->hold * 1_000_000));

                return $this->accepted();
            }

            public function sendBatch(TissEntityOperatorCredential $credential, string $xml, string $idempotencyKey, array $context = []): array
            {
                return $this->accepted();
            }
        };

        app()->instance(TissMessageTransportContract::class, $transport);

        $guide = TissGuide::query()->withoutGlobalScopes()->findOrFail($id);
        $sent  = app(SendSurgicalGuideService::class)->send($guide);

        echo 'sent:' . $sent->status->value . ':' . $sent->id;
    } elseif ($mode === 'generate-hold') {
        $case = SurgicalCase::query()->withoutGlobalScopes()->findOrFail($id);

        DB::transaction(function () use ($case, $marker, $holdSeconds): void {
            $guide = app(SurgicalGuideService::class)->generate($case, TissGuideType::InpatientRequest);

            file_put_contents($marker, 'holding');
            usleep((int) ((float) $holdSeconds * 1_000_000));

            echo 'guide:' . $guide->id;
        });
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());

    exit(1);
}
