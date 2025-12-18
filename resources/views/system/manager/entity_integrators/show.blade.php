<fieldset>
    <div class="table-responsive">
        <table class="table">
            <tbody>
                @if($record->deleted_at)
                    <tr>
                        <th class="text-center bg-light" colspan="2">Integrador inativo (Deletado)</th>
                    </tr>
                @endif
                <tr>
                    <th width="20%">Nome</th>
                    <td>{{ $record->name }}</td>
                </tr>
                <tr>
                    <th>IP</th>
                    <td>{{ $record->ip }}</td>
                </tr>
                <tr>
                    <th>MAC</th>
                    <td>{{ $record->mac }}</td>
                </tr>
                <tr>
                    <th>Token</th>
                    <td>{{ $record->token }}</td>
                </tr>
                <tr>
                    <th>Ativo</th>
                    <td>{{ $record->active ? 'Sim' : 'Não' }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</fieldset>