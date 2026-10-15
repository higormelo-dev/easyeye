{{--
    Bloco opcional de dados estruturados do documento cirúrgico (payload.data).
    Cada tipo grava em data['items'] uma lista de linhas {label, value} que o PDF
    imprime como tabela; tipos sem itens não imprimem nada.
--}}
@php($items = (array) ($data['items'] ?? []))
@if(count($items))
    <table class="items">
        <tbody>
        @foreach($items as $row)
            <tr>
                <th style="width: 32%">{{ $row['label'] ?? '' }}</th>
                <td>{{ $row['value'] ?? '' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif
