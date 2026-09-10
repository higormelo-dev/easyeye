<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Pedido de Compra {{ $po->code }}</title>
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
        .meta { font-size: 10px; color: #6b7280; }
        .parties {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        .parties td {
            border: 1px solid #d1d5db;
            padding: 8px;
            vertical-align: top;
            width: 50%;
        }
        .parties .label { font-weight: 700; color: #374151; margin-bottom: 4px; display: block; }
        table.table { width: 100%; border-collapse: collapse; }
        .table th, .table td {
            border: 1px solid #e5e7eb;
            padding: 6px;
            vertical-align: top;
        }
        .table th { background: #f3f4f6; font-weight: 700; }
        .text-end { text-align: right; }
        .muted { color: #6b7280; }
        .total-row td { font-weight: 700; background: #f9fafb; }
        .notes {
            margin-top: 16px;
            padding: 8px;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            background: #f9fafb;
        }
        .signature {
            margin-top: 60px;
            display: table;
            width: 100%;
        }
        .signature .line {
            display: table-cell;
            width: 50%;
            text-align: center;
            padding-top: 8px;
            border-top: 1px solid #9ca3af;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Pedido de Compra {{ $po->code }}</div>
        <div class="meta">
            Status: {{ $po->status->label() }}<br>
            Data do pedido: {{ $po->order_date?->format('d/m/Y') }}
            @if($po->expected_delivery_date)
                — Previsão de entrega: {{ $po->expected_delivery_date->format('d/m/Y') }}
            @endif
            <br>
            Gerado em: {{ $generatedAt->format('d/m/Y H:i') }}
        </div>
    </div>

    <table class="parties">
        <tr>
            <td>
                <span class="label">Comprador</span>
                {{ $entity->name }}
                @if($entity->national_registration)
                    <br>CNPJ: {{ $entity->national_registration }}
                @endif
            </td>
            <td>
                <span class="label">Fornecedor</span>
                {{ $po->supplier->name }}
                @if($po->supplier->document)
                    <br>Documento: {{ $po->supplier->document }}
                @endif
                @if($po->supplier->contact_name)
                    <br>Contato: {{ $po->supplier->contact_name }}
                @endif
                @if($po->supplier->email)
                    <br>E-mail: {{ $po->supplier->email }}
                @endif
                @if($po->supplier->phone)
                    <br>Telefone: {{ $po->supplier->phone }}
                @endif
            </td>
        </tr>
    </table>

    <table class="table">
        <thead>
            <tr>
                <th>Produto</th>
                <th>Código</th>
                <th class="text-end">Quantidade</th>
                <th class="text-end">Custo unitário</th>
                <th class="text-end">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($po->items as $item)
                <tr>
                    <td>{{ $item->product?->name }}</td>
                    <td>{{ $item->product?->code }}</td>
                    <td class="text-end">{{ rtrim(rtrim(number_format((float) $item->quantity_ordered, 3, ',', '.'), '0'), ',') }} {{ $item->product?->unit?->label() }}</td>
                    <td class="text-end">R$ {{ number_format((float) $item->unit_cost, 2, ',', '.') }}</td>
                    <td class="text-end">R$ {{ number_format((float) $item->subtotal, 2, ',', '.') }}</td>
                </tr>
            @endforeach
            <tr class="total-row">
                <td colspan="4" class="text-end">Total</td>
                <td class="text-end">R$ {{ number_format((float) $po->total_amount, 2, ',', '.') }}</td>
            </tr>
        </tbody>
    </table>

    @if($po->notes)
        <div class="notes">
            <strong>Observações:</strong><br>
            {{ $po->notes }}
        </div>
    @endif

    <div class="signature">
        <div class="line">Comprador</div>
        <div class="line">Fornecedor</div>
    </div>
</body>
</html>
