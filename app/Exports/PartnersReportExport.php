<?php

declare(strict_types=1);

namespace App\Exports;

use App\Enums\CommissionStatus;
use App\Enums\PartnerLeadStatus;
use App\Models\Partner;
use App\Models\PartnerCommission;
use App\Models\PartnerLead;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Relatório consolidado de Parceiros (Manager SaaS) em Excel multi-aba.
 *
 * Abas:
 *   1) Resumo       — KPIs e período do recorte
 *   2) Parceiros    — lista completa com totais por parceiro
 *   3) Leads        — distribuição por status (funnel)
 *   4) Comissões    — uma linha por comissão (auditável para contabilidade)
 *
 * Filtros aplicáveis:
 *   - from        : data inicial (filtra Commissions/Leads por created_at)
 *   - to          : data final  (idem)
 *   - status      : 'pending' | 'paid' | 'cancelled' (filtra apenas a aba Comissões)
 *
 * Os filtros refletem o que o usuário selecionou na tela /panel/manager/partners,
 * preservando consistência entre o que ele vê e o que ele exporta.
 */
class PartnersReportExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ?CarbonImmutable $from = null,
        private readonly ?CarbonImmutable $to = null,
        private readonly ?string $status = null,
    ) {
    }

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new PartnersSummarySheet($this->from, $this->to, $this->status),
            new PartnersListSheet(),
            new PartnersLeadsSheet($this->from, $this->to),
            new PartnersCommissionsSheet($this->from, $this->to, $this->status),
        ];
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Aba 1 — Resumo
   ───────────────────────────────────────────────────────────────────────────── */

final class PartnersSummarySheet implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    use Exportable;

    public function __construct(
        private readonly ?CarbonImmutable $from,
        private readonly ?CarbonImmutable $to,
        private readonly ?string $status,
    ) {
    }

    public function title(): string
    {
        return 'Resumo';
    }

    public function headings(): array
    {
        return ['Métrica', 'Valor'];
    }

    public function collection()
    {
        $commissionsQuery = PartnerCommission::query();
        $leadsQuery = PartnerLead::query();

        if ($this->from) {
            $commissionsQuery->where('created_at', '>=', $this->from);
            $leadsQuery->where('created_at', '>=', $this->from);
        }
        if ($this->to) {
            $commissionsQuery->where('created_at', '<=', $this->to);
            $leadsQuery->where('created_at', '<=', $this->to);
        }

        $pending = (clone $commissionsQuery)->where('status', CommissionStatus::Pending)->sum('amount');
        $paid    = (clone $commissionsQuery)->where('status', CommissionStatus::Paid)->sum('amount');

        $period = $this->formatPeriod();

        return collect([
            ['Período', $period],
            ['Filtro de status (aba Comissões)', $this->status ?: 'todos'],
            ['Total de parceiros ativos', Partner::where('active', true)->count()],
            ['Total de parceiros (todos)', Partner::count()],
            ['Total de leads no período', $leadsQuery->count()],
            ['Total de comissões no período', $commissionsQuery->count()],
            ['Comissões pendentes (R$)', number_format((float) $pending, 2, ',', '.')],
            ['Comissões pagas (R$)', number_format((float) $paid, 2, ',', '.')],
            ['Gerado em', now()->format('d/m/Y H:i:s')],
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function formatPeriod(): string
    {
        if ($this->from && $this->to) {
            return $this->from->format('d/m/Y') . ' a ' . $this->to->format('d/m/Y');
        }
        if ($this->from) {
            return 'A partir de ' . $this->from->format('d/m/Y');
        }
        if ($this->to) {
            return 'Até ' . $this->to->format('d/m/Y');
        }
        return 'Todo o histórico';
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Aba 2 — Parceiros
   ───────────────────────────────────────────────────────────────────────────── */

final class PartnersListSheet implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    use Exportable;

    public function title(): string
    {
        return 'Parceiros';
    }

    public function headings(): array
    {
        return ['Código', 'Nome', 'E-mail', 'Tipo', 'Ativo', 'Comissão %', 'Leads', 'Comissões', 'Pendente (R$)', 'Pago (R$)', 'Cadastrado em'];
    }

    public function collection()
    {
        return Partner::query()
            ->withCount(['leads', 'commissions'])
            ->withSum(
                ['commissions as pending_amount' => fn ($q) => $q->where('status', CommissionStatus::Pending)],
                'amount'
            )
            ->withSum(
                ['commissions as paid_amount' => fn ($q) => $q->where('status', CommissionStatus::Paid)],
                'amount'
            )
            ->orderBy('name')
            ->get()
            ->map(fn (Partner $p) => [
                $p->code ?? '',
                $p->name,
                $p->email,
                $p->type?->label() ?? '—',
                $p->active ? 'Sim' : 'Não',
                $p->commission_rate !== null ? number_format((float) $p->commission_rate, 2, ',', '.') : '—',
                (int) ($p->leads_count ?? 0),
                (int) ($p->commissions_count ?? 0),
                number_format((float) ($p->pending_amount ?? 0), 2, ',', '.'),
                number_format((float) ($p->paid_amount ?? 0), 2, ',', '.'),
                $p->created_at?->format('d/m/Y'),
            ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'ECEDF7']]],
        ];
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Aba 3 — Leads por status (funnel)
   ───────────────────────────────────────────────────────────────────────────── */

final class PartnersLeadsSheet implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    use Exportable;

    public function __construct(
        private readonly ?CarbonImmutable $from,
        private readonly ?CarbonImmutable $to,
    ) {
    }

    public function title(): string
    {
        return 'Leads';
    }

    public function headings(): array
    {
        return ['Status', 'Quantidade'];
    }

    public function collection()
    {
        return collect(PartnerLeadStatus::cases())->map(function (PartnerLeadStatus $s) {
            $q = PartnerLead::query()->where('status', $s);
            if ($this->from) $q->where('created_at', '>=', $this->from);
            if ($this->to)   $q->where('created_at', '<=', $this->to);

            return [$s->label(), $q->count()];
        });
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'ECEDF7']]],
        ];
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   Aba 4 — Comissões (auditável)
   ───────────────────────────────────────────────────────────────────────────── */

final class PartnersCommissionsSheet implements FromCollection, WithHeadings, WithTitle, WithStyles, ShouldAutoSize
{
    use Exportable;

    public function __construct(
        private readonly ?CarbonImmutable $from,
        private readonly ?CarbonImmutable $to,
        private readonly ?string $status,
    ) {
    }

    public function title(): string
    {
        return 'Comissões';
    }

    public function headings(): array
    {
        return ['Parceiro', 'Clínica', 'Valor (R$)', 'Status', 'Vencimento', 'Pago em', 'Criada em'];
    }

    public function collection()
    {
        $q = PartnerCommission::query()
            ->with(['partner:id,name', 'entity:id,name'])
            ->orderByDesc('created_at');

        if ($this->from) {
            $q->where('created_at', '>=', $this->from);
        }
        if ($this->to) {
            $q->where('created_at', '<=', $this->to);
        }
        if ($this->status) {
            $statusEnum = CommissionStatus::tryFrom($this->status);
            if ($statusEnum) {
                $q->where('status', $statusEnum);
            }
        }

        return $q->get()->map(fn (PartnerCommission $c) => [
            $c->partner?->name ?? '—',
            $c->entity?->name ?? '—',
            number_format((float) $c->amount, 2, ',', '.'),
            $c->status->label(),
            $c->due_at?->format('d/m/Y') ?? '—',
            $c->paid_at?->format('d/m/Y') ?? '—',
            $c->created_at?->format('d/m/Y H:i'),
        ]);
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'ECEDF7']]],
        ];
    }
}
