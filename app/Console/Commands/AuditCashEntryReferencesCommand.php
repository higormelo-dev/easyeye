<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CashEntryReferenceType;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Auditoria SOMENTE LEITURA do vínculo de sistema dos lançamentos de caixa
 * (financial_cash_entries.reference_type / reference_id).
 *
 * Até a correção o CashEntryRequest aceitava esses campos do cliente, então a
 * base pode ter linhas forjadas. O comando lista, por categoria, a contagem e
 * uma amostra de ids (lançamento | clínica) — sem descrição, observações,
 * valores ou dados de paciente (LGPD). Nada é alterado: a correção de cada
 * linha é decisão de negócio (cancelar, limpar o vínculo ou manter).
 *
 * Uso:
 *   php artisan financial:audit-cash-references
 *   php artisan financial:audit-cash-references --entity=<uuid> --limit=200 --with-trashed
 *
 * Exit code: 0 sem inconsistências; 1 com inconsistências (útil em cron/CI);
 * 2 para opção inválida.
 */
class AuditCashEntryReferencesCommand extends Command
{
    private const MAX_LIMIT = 1000;

    protected $signature = 'financial:audit-cash-references
                            {--entity= : limita a uma clínica (UUID)}
                            {--limit=50 : quantidade máxima de ids listados por categoria}
                            {--with-trashed : inclui lançamentos excluídos (soft delete)}';

    protected $description = 'Relatório somente leitura de lançamentos de caixa com vínculo (reference_type/reference_id) fora da whitelist, inexistente ou de outra clínica.';

    public function handle(): int
    {
        $entityId = $this->option('entity');
        $limit    = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => self::MAX_LIMIT]]);

        if ($entityId !== null && ! Str::isUuid((string) $entityId)) {
            $this->error(__('financial_cash_flow.audit_references.invalid_entity'));

            return self::INVALID;
        }

        if ($limit === false) {
            $this->error(__('financial_cash_flow.audit_references.invalid_limit', ['max' => self::MAX_LIMIT]));

            return self::INVALID;
        }

        $this->line($entityId === null
            ? __('financial_cash_flow.audit_references.scope_all')
            : __('financial_cash_flow.audit_references.scope_entity', ['entity' => $entityId]));

        $counts = [];
        $total  = 0;

        foreach ($this->issueQueries() as $issue => $constrain) {
            $counts[$issue] = $constrain($this->baseQuery($entityId))->count();
            $total += $counts[$issue];
        }

        $this->table(
            [__('financial_cash_flow.audit_references.col_issue'), __('financial_cash_flow.audit_references.col_count')],
            collect($counts)->map(fn (int $count, string $issue) => [__("financial_cash_flow.audit_references.issues.{$issue}"), $count])->values()->all(),
        );

        if ($total === 0) {
            $this->info(__('financial_cash_flow.audit_references.none'));

            return self::SUCCESS;
        }

        foreach ($this->issueQueries() as $issue => $constrain) {
            if ($counts[$issue] === 0) {
                continue;
            }

            $this->newLine();
            $this->warn(__('financial_cash_flow.audit_references.sample', [
                'issue' => __("financial_cash_flow.audit_references.issues.{$issue}"),
                'limit' => $limit,
            ]));

            $constrain($this->baseQuery($entityId))
                ->orderBy('e.entity_id')
                ->orderBy('e.id')
                ->limit($limit)
                ->get(['e.id', 'e.entity_id'])
                ->each(fn (object $row) => $this->line("  {$row->id} | {$row->entity_id}"));
        }

        $this->newLine();
        $this->warn(__('financial_cash_flow.audit_references.found', ['count' => $total]));

        return self::FAILURE;
    }

    /**
     * Query builder puro (sem Eloquent): não hidrata model, não dispara
     * cast/evento e não depende do EntityScope — leitura cross-clínica
     * explícita de um comando de operação.
     */
    private function baseQuery(?string $entityId): Builder
    {
        return DB::table('financial_cash_entries as e')
            ->when(! $this->option('with-trashed'), fn (Builder $q) => $q->whereNull('e.deleted_at'))
            ->when($entityId !== null, fn (Builder $q) => $q->where('e.entity_id', $entityId));
    }

    /**
     * Categorias mutuamente exclusivas: cada lançamento aparece em no máximo uma.
     *
     * @return array<string, callable(Builder): Builder>
     */
    private function issueQueries(): array
    {
        $allowed = CashEntryReferenceType::values();

        return [
            'unknown_type' => fn (Builder $q) => $q
                ->whereNotNull('e.reference_type')
                ->whereNotIn('e.reference_type', $allowed),

            'incomplete' => fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where(fn (Builder $c) => $c->whereIn('e.reference_type', $allowed)->whereNull('e.reference_id'))
                ->orWhere(fn (Builder $c) => $c->whereNull('e.reference_type')->whereNotNull('e.reference_id'))),

            'missing_target' => fn (Builder $q) => $q->where(fn (Builder $w) => $this->eachType($w, fn (Builder $c, CashEntryReferenceType $type) => $c
                ->whereNotExists(fn (Builder $t) => $this->target($t, $type)))),

            'cross_entity' => fn (Builder $q) => $q->where(fn (Builder $w) => $this->eachType($w, fn (Builder $c, CashEntryReferenceType $type) => $c
                ->whereExists(fn (Builder $t) => $this->target($t, $type)->whereColumn('t.entity_id', '<>', 'e.entity_id')))),

            // BillingService grava billing_claim_id = reference_id; divergência
            // indica vínculo que não veio do recebimento da guia.
            'claim_mismatch' => fn (Builder $q) => $q
                ->where('e.reference_type', CashEntryReferenceType::BillingClaim->value)
                ->whereExists(fn (Builder $t) => $this->target($t, CashEntryReferenceType::BillingClaim)->whereColumn('t.entity_id', 'e.entity_id'))
                ->where(fn (Builder $c) => $c->whereNull('e.billing_claim_id')->orWhereColumn('e.billing_claim_id', '<>', 'e.reference_id')),
        ];
    }

    /**
     * OR entre os tipos da whitelist (com reference_id preenchido), aplicando
     * a condição específica de cada tipo.
     *
     * @param callable(Builder, CashEntryReferenceType): Builder $condition
     */
    private function eachType(Builder $query, callable $condition): Builder
    {
        foreach (CashEntryReferenceType::cases() as $type) {
            $query->orWhere(fn (Builder $c) => $condition(
                $c->where('e.reference_type', $type->value)->whereNotNull('e.reference_id'),
                $type,
            ));
        }

        return $query;
    }

    /** Subquery do registro referenciado (tabela do tipo, alias `t`). */
    private function target(Builder $query, CashEntryReferenceType $type): Builder
    {
        return $query->selectRaw('1')
            ->from($type->table() . ' as t')
            ->whereColumn('t.id', 'e.reference_id');
    }
}
