<?php

use App\Broadcasting\{ClinicImportChannel, ManagerAiCatalogSyncChannel, ManagerCovenantImportChannel, ManagerMedicineImportChannel};
use Illuminate\Support\Facades\Broadcast;

/*
| Canais privados (WebSocket via Reverb). Autorização em /broadcasting/auth,
| na sessão web do usuário — cada classe repete a regra da tela que assina.
*/

// Progresso das importações de clínica: imports.patients|doctors|schedules.{id}
Broadcast::channel('imports.{type}.{importId}', ClinicImportChannel::class);

// Progresso da importação do catálogo global de medicamentos (manager).
Broadcast::channel('manager.imports.medicines.{importId}', ManagerMedicineImportChannel::class);

// Progresso da sincronização do catálogo global de convênios com a ANS (manager).
Broadcast::channel('manager.imports.covenants.{importId}', ManagerCovenantImportChannel::class);

// Progresso da sincronização do catálogo de modelos/preços de IA (manager).
Broadcast::channel('manager.ai-catalog-syncs.{syncId}', ManagerAiCatalogSyncChannel::class);
