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
                    <th>{{ __('actions.code') }}</th>
                    <td>{{ $record->code }}</td>
                </tr>
                <tr>
                    <th>Convênio</th>
                    <td>{{ $record->covenant->name }}</td>
                </tr>
                <tr>
                    <th>Cútis</th>
                    <td>{{ $record->skinType->name }}</td>
                </tr>
                <tr>
                    <th>Íris</th>
                    <td>{{ $record->irisType->name }}</td>
                </tr>
                <tr>
                    <th width="20%">Nome completo</th>
                    <td>{{ $record->person->full_name }}</td>
                </tr>
                <tr>
                    <th width="20%">Apelido</th>
                    <td>{{ $record->person->nickname }}</td>
                </tr>
                <tr>
                    <th>CPF</th>
                    <td>{{ $record->person->present()->getNationalRegistry }}</td>
                </tr>
                <tr>
                    <th>Data de nascimento</th>
                    <td>{{ $record->person->present()->getBirthDate }}</td>
                </tr>
                <tr>
                    <th>Sexo</th>
                    <td>{{ $record->person->present()->getGender }}</td>
                </tr>
                <tr>
                    <th>Estado civil</th>
                    <td>{{ $record->person->present()->getMaritalStatus }}</td>
                </tr>
                <tr>
                    <th>E-mail</th>
                    <td>{{ $record->person->email }}</td>
                </tr>
                <tr>
                    <th>Nome da mãe</th>
                    <td>{{ $record->person->mother_name }}</td>
                </tr>
                <tr>
                    <th>Nome do pai</th>
                    <td>{{ $record->person->father_name }}</td>
                </tr>
                <tr>
                    <th>Nome do pai</th>
                    <td>{{ $record->person->state_registry }}</td>
                </tr>
                <tr>
                    <th>Órgão emissor do RG</th>
                    <td>{{ $record->person->state_registry_agency }}</td>
                </tr>
                <tr>
                    <th>Estado do RG</th>
                    <td>{{ $record->person->state_registry_initial }}</td>
                </tr>
                <tr>
                    <th>Data de emissão do RG</th>
                    <td>{{ $record->person->present()->getStateRegistryDate }}</td>
                </tr>
                <tr>
                    <th>Telefone</th>
                    <td>{{ $record->person->present()->getTelephone }}</td>
                </tr>
                <tr>
                    <th>Celular</th>
                    <td>{{ $record->person->present()->getCellphone }}</td>
                </tr>
                <tr>
                    <th>CEP</th>
                    <td>{{ $record->person->present()->getZipcode }}</td>
                </tr>
                <tr>
                    <th>Logradouro</th>
                    <td>{{ $record->person->address }}</td>
                </tr>
                <tr>
                    <th>Número</th>
                    <td>{{ $record->person->number }}</td>
                </tr>
                <tr>
                    <th>Complemento</th>
                    <td>{{ $record->person->complement }}</td>
                </tr>
                <tr>
                    <th>Bairro</th>
                    <td>{{ $record->person->district }}</td>
                </tr>
                <tr>
                    <th>Cidade</th>
                    <td>{{ $record->person->city }}</td>
                </tr>
                <tr>
                    <th>Estado</th>
                    <td>{{ $record->person->state }}</td>
                </tr>
                <tr>
                    <th>Parceiro</th>
                    <td>{{ $record->partner ? 'Sim' : 'Não' }}</td>
                </tr>
                <tr>
                    <th>Ativo</th>
                    <td>{{ $record->active ? 'Sim' : 'Não' }}</td>
                </tr>
                <tr>
                    <th>Data de criação</th>
                    <td>{{ $record->created_at->format('d/m/Y H:i') }}</td>
                </tr>
                @if($record->created_at && $record->updated_at && $record->created_at->format('d/m/Y H:i') !== $record->updated_at->format('d/m/Y H:i'))
                    <tr>
                        <th>Data de atualização</th>
                        <td>{{ $record->updated_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endif
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