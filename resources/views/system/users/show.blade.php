<fieldset>
    <div class="table-responsive">
        <table class="table">
            <tbody>
                @if($record->deleted_at)
                    <tr>
                        <th class="text-center bg-light" colspan="2">Usuário inativo (Deletado)</th>
                    </tr>
                @endif
                <tr>
                    <th width="20%">Nome</th>
                    <td>{{ $record->user->name }}</td>
                </tr>
                <tr>
                    <th>E-mail</th>
                    <td>{{ $record->user->email }}</td>
                </tr>
                <tr>
                    <th>Perfil</th>
                    <td>
                        @if($record->entity->is_client)
                            {{ \App\Models\User::$rolesOfClients[$record->rule] }}
                        @else
                            {{ \App\Models\User::$rolesOfManager[$record->rule] }}
                        @endif
                    </td>
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