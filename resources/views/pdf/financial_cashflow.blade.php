<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Relatório de Fluxo de Caixa</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #1f2937;
        }
        .header {
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 16px;
            padding-bottom: 10px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
            margin-bottom: 4px;
        }
        .meta {
            font-size: 10px;
            color: #6b7280;
        }
        .summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .summary th,
        .summary td {
            border: 1px solid #d1d5db;
            padding: 8px;
            text-align: left;
        }
        .summary th {
            background: #f3f4f6;
        }
        table.table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th,
        .table td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            vertical-align: top;
        }
        .table th {
            background: #f3f4f6;
            font-weight: 700;
        }
        .text-end { text-align: right; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Relatório de Fluxo de Caixa</div>
        <div class="meta">
            Clínica: {{ $entity->name }}<br>
            Período: {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} até {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}<br>
            Gerado em: {{ $generatedAt->format('d/m/Y H:i') }}
        </div>
    </div>

    <table class="summary">
        <thead>
            <tr>
                <th>Total de receitas</th>
                <th>Total de despesas</th>
                <th>Saldo do período</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>R$ {{ number_format((float) $summary['income'], 2, ',', '.') }}</td>
                <td>R$ {{ number_format((float) $summary['expense'], 2, ',', '.') }}</td>
                <td>R$ {{ number_format((float) $summary['balance'], 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>Data</th>
                <th>Código</th>
                <th>Descrição</th>
                <th>Categoria</th>
                <th>Convênio</th>
                <th>Tipo</th>
                <th>Status</th>
                <th class="text-end">Valor</th>
            </tr>
        </thead>
        <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td>{{ $entry->entry_date?->format('d/m/Y') }}</td>
                    <td>{{ $entry->code }}</td>
                    <td>{{ $entry->description }}</td>
                    <td>{{ $entry->category?->name ?? 'Sem categoria' }}</td>
                    <td>{{ $entry->covenant?->name ?? 'Sem convênio' }}</td>
                    <td>{{ $entry->type->label() }}</td>
                    <td>{{ $entry->status->label() }}</td>
                    <td class="text-end">R$ {{ number_format((float) $entry->amount, 2, ',', '.') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="muted">Nenhum lançamento no período.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
