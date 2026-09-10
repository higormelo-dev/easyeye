<?php

declare(strict_types=1);

namespace App\Services\Stock;

use App\Enums\FeatureKey;
use App\Models\{Entity, EntityProduct, EntityUser, Notice, StockLot};
use App\Services\FeatureGateService;
use Illuminate\Support\Collection;

/**
 * Alertas de estoque baixo/lote vencendo — GAP fechado nesta revisão (Fases
 * 1-2 deixaram só o badge/filtro na tela, sem nenhum empurrão proativo).
 *
 * Reaproveita App\Models\Notice (mural de avisos do painel, já existente e
 * usado por toda a equipe) em vez de construir canal novo (e-mail/WhatsApp)
 * — menor risco, zero infra nova, e o aviso já aparece de cara no painel de
 * quem loga. `author_id` é NOT NULL na tabela (não dá pra ter aviso "sem
 * autor") — usa o OWNER da entity como autor do aviso automático; sem
 * owner resolvível, pula a entidade (log, não quebra o comando).
 *
 * Dedup: cada rodada REMOVE o aviso automático anterior (marcado por
 * MARKER) antes de criar o novo — evita acumular um aviso por dia pra
 * sempre; sempre existe no máximo 1 aviso automático de estoque por vez
 * por clínica, sempre com o retrato mais atual.
 */
class StockAlertService
{
    /**
     * Prefixo que identifica um aviso como GERADO POR ESTE SERVICE (não por
     * uma pessoa) — usado só pra encontrar/substituir o aviso anterior na
     * mesma clínica; nunca combinar com conteúdo digitado por humano (nenhum
     * humano digitaria este emoji+texto por coincidência).
     */
    public const MARKER = '🔔 [Alerta de estoque automático]';

    /** Mesmo horizonte usado no filtro "Lotes vencendo" da tela de Produtos. */
    private const EXPIRY_HORIZON_DAYS = 30;

    public function __construct(
        private readonly FeatureGateService $featureGate,
    ) {
    }

    /**
     * Roda pra todas as entidades cliente ativas com o módulo de estoque
     * habilitado no plano. Retorna quantos avisos foram (re)criados.
     */
    public function checkAllEntities(): int
    {
        $created = 0;

        Entity::query()
            ->where('is_client', true)
            ->where('active', true)
            ->chunkById(50, function ($entities) use (&$created) {
                foreach ($entities as $entity) {
                    if ($this->checkEntity($entity) !== null) {
                        $created++;
                    }
                }
            });

        return $created;
    }

    /**
     * @return Notice|null Notice criado, ou null quando não há nada a
     *                     alertar (ou a feature está desabilitada) — nesse
     *                     caso, qualquer aviso automático antigo é removido.
     */
    public function checkEntity(Entity $entity): ?Notice
    {
        $entityId = (string) $entity->id;

        if (! $this->featureGate->status($entityId, FeatureKey::HasInventoryModule)->allowed) {
            $this->clearPreviousAlert($entityId);

            return null;
        }

        $lowStockProducts = EntityProduct::query()
            ->where('entity_id', $entityId)
            ->active()
            ->belowMinimum()
            ->orderBy('name')
            ->get(['id', 'name', 'qty_on_hand', 'min_qty']);

        $expiringLots = StockLot::query()
            ->where('entity_id', $entityId)
            ->active()
            ->withBalance()
            ->expiringWithin(self::EXPIRY_HORIZON_DAYS)
            ->with('product:id,name')
            ->orderBy('expiry_date')
            ->get();

        $this->clearPreviousAlert($entityId);

        if ($lowStockProducts->isEmpty() && $expiringLots->isEmpty()) {
            return null;
        }

        $author = $this->resolveAuthor($entity);

        if ($author === null) {
            return null; // entidade sem owner/admin resolvível — não quebra o comando
        }

        return Notice::create([
            'entity_id' => $entityId,
            'author_id' => $author->id,
            'content'   => $this->buildContent($lowStockProducts, $expiringLots),
            'priority'  => $this->hasExpiredLot($expiringLots) ? 1 : 0,
            'pinned'    => true,
            // Expira sozinho se ninguém rodar o comando de novo por uma
            // semana (clínica com scheduler parado não fica com aviso
            // "eterno" desatualizado preso no topo do mural).
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function clearPreviousAlert(string $entityId): void
    {
        Notice::query()
            ->where('entity_id', $entityId)
            ->where('content', 'like', self::MARKER . '%')
            ->delete();
    }

    private function hasExpiredLot($lots): bool
    {
        return $lots->contains(fn (StockLot $lot) => $lot->isExpired());
    }

    private function resolveAuthor(Entity $entity): ?EntityUser
    {
        return EntityUser::query()
            ->where('entity_id', $entity->id)
            ->where('active', true)
            ->orderByDesc('is_owner')
            ->orderBy('created_at')
            ->first();
    }

    /**
     * @param Collection<int, EntityProduct> $lowStockProducts
     * @param Collection<int, StockLot>      $expiringLots
     */
    private function buildContent($lowStockProducts, $expiringLots): string
    {
        $lines = [self::MARKER, ''];

        if ($lowStockProducts->isNotEmpty()) {
            $lines[] = "📦 Estoque baixo ({$lowStockProducts->count()}):";

            foreach ($lowStockProducts as $product) {
                $lines[] = "- {$product->name}: {$product->qty_on_hand} (mínimo {$product->min_qty})";
            }
            $lines[] = '';
        }

        if ($expiringLots->isNotEmpty()) {
            $lines[] = '⏳ Lotes vencendo em até ' . self::EXPIRY_HORIZON_DAYS . " dias ({$expiringLots->count()}):";

            foreach ($expiringLots as $lot) {
                $status  = $lot->isExpired() ? 'VENCIDO' : ('vence ' . $lot->expiry_date->format('d/m/Y'));
                $lines[] = "- {$lot->product?->name} — lote {$lot->lot_number} ({$status})";
            }
        }

        return trim(implode("\n", $lines)) . "\n";
    }
}
