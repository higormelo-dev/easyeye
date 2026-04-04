# Módulo TISS (Domínio)

Exemplo de uso no Laravel:

```php
use App\Domains\Tiss\Services\TissWorkflowService;

$workflow = app(TissWorkflowService::class);

$batch = $workflow->createBatch([
    'entity_id' => $entityId,
    'operator_id' => $operatorId,
    'contract_id' => $contractId,
    'reference_month' => now()->format('Y-m'),
]);

$guide = $workflow->createGuide([
    'entity_id' => $entityId,
    'operator_id' => $operatorId,
    'contract_id' => $contractId,
    'patient_id' => $patientId,
    'doctor_id' => $doctorId,
    'guide_type' => 'sadt',
    'items' => [
        [
            'tuss_code' => '30301257',
            'description' => 'TOMOGRAFIA DE COERÊNCIA ÓPTICA',
            'quantity' => 1,
            'unit_amount' => 200,
        ],
    ],
]);

$workflow->attachGuideToBatch($batch, $guide);
$workflow->generateXmlInQueue($batch);
$workflow->sendBatchInQueue($batch);
```

Recebimento de XML retorno:

```php
$workflow->receiveResponse(
    entityId: $entityId,
    operatorId: $operatorId,
    xmlContent: $xmlRetorno,
    processSynchronously: false,
);
```
