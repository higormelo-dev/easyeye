<?php

use App\Broadcasting\{ClinicBillingChannel, ClinicImportChannel, ManagerAiCatalogSyncChannel, ManagerCid10ImportChannel, ManagerCovenantImportChannel, ManagerMedicineImportChannel, ManagerMedicinePosologyBatchChannel};
use Illuminate\Support\Facades\Broadcast;

/*
| Canais privados (WebSocket via Reverb). Autorização em /broadcasting/auth,
| na sessão web do usuário — cada classe repete a regra da tela que assina.
*/

// Progresso das importações de clínica: imports.patients|doctors|schedules.{id}
Broadcast::channel('imports.{type}.{importId}', ClinicImportChannel::class);

// Progresso da importação do catálogo global de medicamentos (manager).
Broadcast::channel('manager.imports.medicines.{importId}', ManagerMedicineImportChannel::class);

// Progresso do lote "Gerar posologia com IA" do catálogo global (manager).
Broadcast::channel('manager.medicines.posology-batches.{batchId}', ManagerMedicinePosologyBatchChannel::class);

// Progresso da importação da CID-10 (DATASUS) no catálogo global (manager).
Broadcast::channel('manager.imports.cid10.{importId}', ManagerCid10ImportChannel::class);

// Progresso da sincronização do catálogo global de convênios com a ANS (manager).
Broadcast::channel('manager.imports.covenants.{importId}', ManagerCovenantImportChannel::class);

// Progresso da sincronização do catálogo de modelos/preços de IA (manager).
Broadcast::channel('manager.ai-catalog-syncs.{syncId}', ManagerAiCatalogSyncChannel::class);

// Pagamento da assinatura confirmado (checkout transparente): billing.{entityId}
// — só contatos de cobrança (admin, financeiro, dono) da clínica da sessão.
Broadcast::channel('billing.{entityId}', ClinicBillingChannel::class);
