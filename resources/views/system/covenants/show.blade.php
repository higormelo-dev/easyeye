<fieldset>
    <div class="table-responsive">
        <table class="table">
            <tbody>
                @if($record->deleted_at)
                    <tr>
                        <th class="text-center bg-light" colspan="2">Convênio inativo (Deletado)</th>
                    </tr>
                @endif
                <tr>
                    <th width="20%">Nome</th>
                    <td>{{ $record->name }}</td>
                </tr>
                <tr>
                    <th width="20%">Cor</th>
                    <td>
                        <span class="badge bg-success"
                              style="background-color: {{ $record->color }} !important;">
                            &nbsp;&nbsp;&nbsp;&nbsp;
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Cobrança</th>
                    <td>{{ $record->table ? 'Sim' : 'Não' }}</td>
                </tr>
                <tr>
                    <th>Ativo</th>
                    <td>{{ $record->active ? 'Sim' : 'Não' }}</td>
                </tr>
                <tr>
                    <th>Data de criação</th>
                    <td>{{ $record->created_at->format('d/m/Y H:i') }}</td>
                </tr>
                @if($record->deleted_at)
                    <tr>
                        <th>Data de inativação</th>
                        <td>{{ $record->deleted_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</fieldset>