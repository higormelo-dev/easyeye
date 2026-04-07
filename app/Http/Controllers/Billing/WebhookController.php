<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\WebhookRequest;
use App\Jobs\Billing\ProcessBillingWebhookJob;
use App\Services\Billing\{CorrelationIdService, WebhookIngestionService};
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function __construct(
        private readonly WebhookIngestionService $webhookIngestionService,
        private readonly CorrelationIdService $correlationIdService,
    ) {
    }

    public function __invoke(WebhookRequest $request, string $gateway): JsonResponse
    {
        $gateway       = Str::lower($gateway);
        $correlationId = $this->correlationIdService->resolve($request);

        $event = $this->webhookIngestionService->ingest(
            gatewayCode: $gateway,
            headers: $request->headers->all(),
            body: (string) $request->getContent(),
            correlationId: $correlationId,
        );

        ProcessBillingWebhookJob::dispatch((string) $event->id);

        return response()->json([
            'ok'             => true,
            'event_id'       => $event->id,
            'correlation_id' => $correlationId,
        ]);
    }
}
