<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IntegratorQueueHealthRequest;
use App\Models\IntegratorQueueHealth;
use Illuminate\Http\Response;

/**
 * Retrato do estado atual da fila local do integrador (pendentes/falhas/
 * bloqueados/enviados), sincronizado periodicamente pelo próprio cliente
 * Rust — dono do SaaS ou suporte conferem "o que tá acontecendo agora" numa
 * clínica antes de precisar acessar a máquina remotamente. Sempre upsert:
 * uma linha por integrador, nunca um histórico — ver o doc comment da
 * migration pro raciocínio de escala.
 */
class IntegratorQueueHealthController extends Controller
{
    public function store(IntegratorQueueHealthRequest $request): Response
    {
        $integrator = request()->attributes->get('integrator');

        IntegratorQueueHealth::updateOrCreate(
            ['integrator_id' => $integrator->id],
            [
                ...$request->only([
                    'pending_count',
                    'failed_count',
                    'blocked_count',
                    'sent_last_24h_count',
                    'problems',
                ]),
                'synced_at' => now(),
            ],
        );

        return response()->noContent();
    }
}
