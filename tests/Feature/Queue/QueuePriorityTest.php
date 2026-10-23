<?php

declare(strict_types=1);

use App\Jobs\AI\RunAiWorkflowJob;
use App\Jobs\Billing\{CancelGatewayChargeJob, CancelGatewaySubscriptionJob, ExpireOverdueSubscriptionsJob, ProcessBillingWebhookJob, RecreateGatewayRecurrenceJob, RenewSubscriptionJob, RetryFailedPaymentJob, SendBillingRefundJob};
use App\Jobs\Fiscal\{ProcessNfseWebhookJob, SendNfseJob};
use App\Jobs\{GenerateExamDerivatives, NotifyScheduleChangeJob, ProcessCid10ImportJob, ProcessCovenantImportJob, ProcessDoctorImportJob, ProcessMedicineImportJob, ProcessMedicinePosologyBatchJob, ProcessPatientImportJob, ProcessScheduleImportJob, SyncAiModelCatalogJob};
use App\Jobs\Surgery\SendSurgeryAuthorizationNoticeJob;
use App\Jobs\WhatsApp\{ProcessWhatsAppInboundJob, ProcessWhatsAppStatusJob, SendPhoneVerificationCodeJob, SendSaasWhatsAppNoticeJob, SendWhatsAppMessageJob};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\{Process, Queue};

/**
 * F1 "Filas com prioridade": cada job vai para a fila do seu TIPO — `critical` (OTP, mensagem recebida, webhook de
 * pagamento), `bulk` (importações, catálogos, posologia) ou `default` — e o worker certo a lê (QueueWorkerSmokeTest).
 * Mapa completo em docs/internal/planos/performance-f0-f1-plano.md §3.5.
 */

/** Nomes sentinela por chave de config: provam QUAL chave cada job lê, sem depender do .env da máquina. */
const QUEUE_PROBE_CONFIG = [
    'billing.webhooks.queue'      => 'q-billing-webhook',
    'billing.jobs.queue'          => 'q-billing-jobs',
    'whatsapp.queue'              => 'q-whatsapp',
    'whatsapp.verification_queue' => 'q-otp',
    'whatsapp.inbound_queue'      => 'q-inbound',
    'queue.bulk_queue'            => 'q-bulk',
    'ai.jobs.queue'               => 'q-ai',
    'fiscal.queue'                => 'q-fiscal',
];

/** Instancia o job com argumentos mínimos (ids fictícios, models vazios): nada vai a banco, fila real ou rede. */
function queueProbeJob(string $class): object
{
    $arguments = [];

    foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $type = $parameter->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : 'string';

        $arguments[] = match (true) {
            $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
            $name === Notification::class         => new class() extends Notification {},
            is_subclass_of($name, Model::class)   => new $name(),
            $name === 'array'                     => [],
            $name === 'int'                       => 0,
            default                               => 'probe',
        };
    }

    return new $class(...$arguments);
}

it('cada job vai para a fila do seu tipo (critical, bulk, billing, whatsapp, default)', function (string $class, string $queue) {
    config([...QUEUE_PROBE_CONFIG]);
    Queue::fake();

    dispatch(queueProbeJob($class));

    Queue::assertPushedOn($queue, $class);
})->with([
    'OTP do cadastro → critical'      => [SendPhoneVerificationCodeJob::class, 'q-otp'],
    'mensagem recebida → critical'    => [ProcessWhatsAppInboundJob::class, 'q-inbound'],
    'webhook de pagamento → critical' => [ProcessBillingWebhookJob::class, 'q-billing-webhook'],

    'import CID-10 → bulk'             => [ProcessCid10ImportJob::class, 'q-bulk'],
    'import convênios → bulk'          => [ProcessCovenantImportJob::class, 'q-bulk'],
    'import médicos → bulk'            => [ProcessDoctorImportJob::class, 'q-bulk'],
    'import medicamentos → bulk'       => [ProcessMedicineImportJob::class, 'q-bulk'],
    'import pacientes → bulk'          => [ProcessPatientImportJob::class, 'q-bulk'],
    'import agenda → bulk'             => [ProcessScheduleImportJob::class, 'q-bulk'],
    'posologia → bulk'                 => [ProcessMedicinePosologyBatchJob::class, 'q-bulk'],
    'catálogo de modelos de IA → bulk' => [SyncAiModelCatalogJob::class, 'q-bulk'],

    'cancelar cobrança → jobs de billing'           => [CancelGatewayChargeJob::class, 'q-billing-jobs'],
    'cancelar assinatura → jobs de billing'         => [CancelGatewaySubscriptionJob::class, 'q-billing-jobs'],
    'expirar assinaturas → jobs de billing'         => [ExpireOverdueSubscriptionsJob::class, 'q-billing-jobs'],
    'recriar recorrência → jobs de billing'         => [RecreateGatewayRecurrenceJob::class, 'q-billing-jobs'],
    'renovar assinatura → jobs de billing'          => [RenewSubscriptionJob::class, 'q-billing-jobs'],
    'nova tentativa de pagamento → jobs de billing' => [RetryFailedPaymentJob::class, 'q-billing-jobs'],
    'estorno → jobs de billing'                     => [SendBillingRefundJob::class, 'q-billing-jobs'],

    'envio de WhatsApp → fila do WhatsApp' => [SendWhatsAppMessageJob::class, 'q-whatsapp'],
    'status de entrega → fila do WhatsApp' => [ProcessWhatsAppStatusJob::class, 'q-whatsapp'],
    'aviso do SaaS → fila do WhatsApp'     => [SendSaasWhatsAppNoticeJob::class, 'q-whatsapp'],
    'aviso cirúrgico → fila do WhatsApp'   => [SendSurgeryAuthorizationNoticeJob::class, 'q-whatsapp'],
    'workflow de IA → fila de IA'          => [RunAiWorkflowJob::class, 'q-ai'],
    'webhook fiscal → fila fiscal'         => [ProcessNfseWebhookJob::class, 'q-fiscal'],
    'emissão fiscal → fila fiscal'         => [SendNfseJob::class, 'q-fiscal'],

    'e-mail da agenda → default'   => [NotifyScheduleChangeJob::class, 'default'],
    'derivados de exame → default' => [GenerateExamDerivatives::class, 'default'],
]);

it('os padrões das chaves de fila (sem env) separam critical, default e bulk', function () {
    // Subprocesso com as chaves vazias: o config usa `?:`, então o padrão do arquivo vale mesmo que o .env da máquina
    // (ou .env.testing) defina outro valor. O processo do teste enxerga esse .env e por isso não serve.
    $keys = ['BULK_QUEUE', 'BILLING_WEBHOOK_QUEUE', 'BILLING_JOBS_QUEUE', 'WHATSAPP_QUEUE', 'WHATSAPP_VERIFICATION_QUEUE', 'WHATSAPP_INBOUND_QUEUE'];
    $code = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo json_encode([
            'bulk' => config('queue.bulk_queue'),
            'billing_webhook' => config('billing.webhooks.queue'),
            'billing_jobs' => config('billing.jobs.queue'),
            'whatsapp' => config('whatsapp.queue'),
            'verification' => config('whatsapp.verification_queue'),
            'inbound' => config('whatsapp.inbound_queue'),
        ]);
        PHP;

    $result = Process::path(base_path())->env(array_fill_keys($keys, ''))->run([PHP_BINARY, '-r', $code]);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and(json_decode($result->output(), true))->toBe([
            'bulk'            => 'bulk',
            'billing_webhook' => 'critical',
            'billing_jobs'    => 'default',
            'whatsapp'        => 'default',
            'verification'    => 'critical',
            'inbound'         => 'critical',
        ]);
});
