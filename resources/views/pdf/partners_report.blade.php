{{--
    Relatório consolidado de Parceiros — versão PDF (wkhtmltopdf via Snappy).
    Variáveis esperadas:
      - $period          : string descritiva do período
      - $statusFilter    : 'pending' | 'paid' | 'cancelled' | null
      - $kpis            : ['partners','leads','pending','paid']
      - $partners        : list<Partner> com pending_amount/paid_amount preenchidos
      - $funnel          : list<['label','count']>
      - $recentCommissions: list<PartnerCommission>
      - $generatedAt     : Carbon
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Parceiros</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1f2937;
            margin: 0;
        }
        .header {
            border-bottom: 2px solid #2E37A4;
            margin-bottom: 16px;
            padding-bottom: 10px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            color: #2E37A4;
            margin-bottom: 4px;
        }
        .meta {
            font-size: 10px;
            color: #6b7280;
        }
        .kpi-row {
            display: table;
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px 0;
            margin-bottom: 18px;
        }
        .kpi {
            display: table-cell;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 10px;
            background: #f9fafb;
            width: 25%;
        }
        .kpi-label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 16px;
            font-weight: bold;
            color: #111827;
        }
        h2 {
            font-size: 13px;
            margin: 18px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e5e7eb;
            color: #2E37A4;
        }
        table.table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .table th,
        .table td {
            border: 1px solid #e5e7eb;
            padding: 5px 7px;
            vertical-align: top;
            text-align: left;
        }
        .table th {
            background: #f3f4f6;
            font-weight: bold;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .badge {
            display: inline-block;
            padding: 1px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
        }
        .badge-pending  { background: #fef3c7; color: #92400e; }
        .badge-paid     { background: #d1fae5; color: #065f46; }
        .badge-cancelled{ background: #e5e7eb; color: #4b5563; }
        .funnel-bar {
            background: #ECEDF7;
            height: 14px;
            border-radius: 3px;
            position: relative;
            overflow: hidden;
        }
        .funnel-fill {
            background: #2E37A4;
            height: 100%;
        }
        .footer {
            margin-top: 20px;
            padding-top: 8px;
            border-top: 1px solid #e5e7eb;
            font-size: 9px;
            color: #9ca3af;
            text-align: center;
        }
    </style>
</head>
<body>

    {{-- ── Cabeçalho ─────────────────────────────────────────────────────── --}}
    <div class="header">
        <div class="title">Relatório de Parceiros</div>
        <div class="meta">
            <strong>Período:</strong> {{ $period }}
            @if($statusFilter)
                · <strong>Filtro de status:</strong> {{ ucfirst($statusFilter) }}
            @endif
            · <strong>Gerado em:</strong> {{ $generatedAt->format('d/m/Y H:i') }}
        </div>
    </div>

    {{-- ── KPIs ──────────────────────────────────────────────────────────── --}}
    <div class="kpi-row">
        <div class="kpi">
            <div class="kpi-label">Parceiros</div>
            <div class="kpi-value">{{ $kpis['partners'] ?? 0 }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Leads no período</div>
            <div class="kpi-value">{{ $kpis['leads'] ?? 0 }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Pendente</div>
            <div class="kpi-value">R$ {{ number_format((float) ($kpis['pending'] ?? 0), 2, ',', '.') }}</div>
        </div>
        <div class="kpi">
            <div class="kpi-label">Pago</div>
            <div class="kpi-value">R$ {{ number_format((float) ($kpis['paid'] ?? 0), 2, ',', '.') }}</div>
        </div>
    </div>

    {{-- ── Funil de Leads ────────────────────────────────────────────────── --}}
    @if(($funnel ?? collect())->isNotEmpty())
        @php
            $maxFunnel = max(1, $funnel->max('count'));
        @endphp
        <h2>Funil de Leads</h2>
        <table class="table">
            <thead>
                <tr>
                    <th style="width:25%;">Status</th>
                    <th style="width:10%;" class="text-center">Qtd.</th>
                    <th>Distribuição</th>
                </tr>
            </thead>
            <tbody>
                @foreach($funnel as $stage)
                    <tr>
                        <td>{{ $stage['label'] }}</td>
                        <td class="text-center">{{ $stage['count'] }}</td>
                        <td>
                            <div class="funnel-bar">
                                <div class="funnel-fill" style="width: {{ ($stage['count'] / $maxFunnel) * 100 }}%;"></div>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ── Lista de Parceiros ────────────────────────────────────────────── --}}
    <h2>Parceiros</h2>
    @if(($partners ?? collect())->isEmpty())
        <p style="color:#9ca3af;">Nenhum parceiro cadastrado.</p>
    @else
        <table class="table">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th class="text-center">Ativo</th>
                    <th class="text-right">Leads</th>
                    <th class="text-right">Comissões</th>
                    <th class="text-right">Pendente</th>
                    <th class="text-right">Pago</th>
                </tr>
            </thead>
            <tbody>
                @foreach($partners as $p)
                    <tr>
                        <td>
                            <strong>{{ $p->name }}</strong><br>
                            <span style="font-size:9px;color:#6b7280;">{{ $p->email }}</span>
                        </td>
                        <td>{{ $p->type?->label() ?? '—' }}</td>
                        <td class="text-center">{{ $p->active ? 'Sim' : 'Não' }}</td>
                        <td class="text-right">{{ $p->leads_count ?? 0 }}</td>
                        <td class="text-right">{{ $p->commissions_count ?? 0 }}</td>
                        <td class="text-right">R$ {{ number_format((float) ($p->pending_amount ?? 0), 2, ',', '.') }}</td>
                        <td class="text-right">R$ {{ number_format((float) ($p->paid_amount ?? 0), 2, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ── Comissões do período ──────────────────────────────────────────── --}}
    <h2>Comissões @if($statusFilter) ({{ ucfirst($statusFilter) }}) @endif</h2>
    @if(($recentCommissions ?? collect())->isEmpty())
        <p style="color:#9ca3af;">Nenhuma comissão no período / filtro selecionado.</p>
    @else
        <table class="table">
            <thead>
                <tr>
                    <th>Parceiro</th>
                    <th>Clínica</th>
                    <th class="text-right">Valor</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Vencimento</th>
                    <th class="text-center">Criada em</th>
                </tr>
            </thead>
            <tbody>
                @foreach($recentCommissions as $c)
                    <tr>
                        <td>{{ $c->partner?->name ?? '—' }}</td>
                        <td>{{ $c->entity?->name ?? '—' }}</td>
                        <td class="text-right">R$ {{ number_format((float) $c->amount, 2, ',', '.') }}</td>
                        <td class="text-center">
                            <span class="badge badge-{{ $c->status->value }}">{{ $c->status->label() }}</span>
                        </td>
                        <td class="text-center">{{ $c->due_at?->format('d/m/Y') ?? '—' }}</td>
                        <td class="text-center">{{ $c->created_at?->format('d/m/Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- ── Rodapé ────────────────────────────────────────────────────────── --}}
    <div class="footer">
        EasyEye · Relatório gerado automaticamente · Documento confidencial
    </div>

</body>
</html>
