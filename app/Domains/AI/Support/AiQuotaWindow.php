<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

use Carbon\{CarbonImmutable, CarbonInterface};

/**
 * Janela mensal da franquia de IA de uma assinatura: [início, fim), com
 * 1 mês de duração, ancorada no dia em que a franquia foi concedida pela
 * 1ª vez (ativação paga). As janelas não "andam": a k-ésima começa em
 * âncora + k meses (sem transbordar — âncora 31/01 → 28/02, 31/03, 30/04…),
 * então ciclos longos (trimestral, semestral, anual) recebem a franquia todo
 * mês e "Adicionar período" ou a renovação paga não reiniciam a janela.
 */
final readonly class AiQuotaWindow
{
    private function __construct(
        public CarbonImmutable $anchor,
        public int $index,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
    }

    /** Janela que contém `$at`, a partir da âncora (início do dia). */
    public static function containing(CarbonInterface $anchor, ?CarbonInterface $at = null): self
    {
        $anchor = CarbonImmutable::instance($anchor)->startOfDay();
        $at     = CarbonImmutable::instance($at ?? now());

        if ($at->lessThan($anchor)) {
            return self::at($anchor, 0);
        }

        $index = ($at->year - $anchor->year) * 12 + ($at->month - $anchor->month);

        if ($anchor->addMonthsNoOverflow($index)->greaterThan($at)) {
            $index--;
        }

        return self::at($anchor, max(0, $index));
    }

    public static function at(CarbonInterface $anchor, int $index): self
    {
        $anchor = CarbonImmutable::instance($anchor)->startOfDay();

        return new self(
            anchor: $anchor,
            index: $index,
            start: $anchor->addMonthsNoOverflow($index),
            end: $anchor->addMonthsNoOverflow($index + 1),
        );
    }

    /** Chave idempotente da concessão desta janela para a assinatura. */
    public function grantKey(string $subscriptionId): string
    {
        return "ai-quota-window:{$subscriptionId}:{$this->start->toDateString()}";
    }
}
