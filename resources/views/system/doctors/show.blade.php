<fieldset>
    <table class="table">
        <tbody>
            @if($record->deleted_at)
                <tr>
                    <th class="text-center bg-light" colspan="2">{{ __('actions.deleted_record') }}</th>
                </tr>
            @endif
            <tr>
                <th width="20%">{{ __('forms.full_name') }}</th>
                <td>{{ $record->person->full_name }}</td>
            </tr>
            <tr>
                <th width="20%">{{ __('forms.display_name') }}</th>
                <td>{{ $record->entityUser->user->name }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.national_registry') }}</th>
                <td>{{ $record->person->present()->getNationalRegistry }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.record') }}</th>
                <td>{{ $record->record }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.record_specialty') }}</th>
                <td>{{ $record->record_specialty }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.color') }}</th>
                <td>
                    <span class="badge" style="background-color: {{ $record->color }} !important;">
                        &nbsp;&nbsp;&nbsp;
                    </span>
                </td>
            </tr>
            <tr>
                <th>{{ __('forms.birth_date') }}</th>
                <td>{{ $record->person->present()->getBirthDate }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.gender') }}</th>
                <td>{{ $record->person->present()->getGender }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.marital_status') }}</th>
                <td>{{ $record->person->present()->getMaritalStatus }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.email') }}</th>
                <td>{{ $record->entityUser->user->email }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.mother_name') }}</th>
                <td>{{ $record->person->mother_name }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.father_name') }}</th>
                <td>{{ $record->person->father_name }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.state_registry') }}</th>
                <td>{{ $record->person->state_registry }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.state_registry_agency') }}</th>
                <td>{{ $record->person->state_registry_agency }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.state_registry_initial') }}</th>
                <td>{{ $record->person->state_registry_initial }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.state_registry_date') }}</th>
                <td>{{ $record->person->present()->getStateRegistryDate }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.telephone') }}</th>
                <td>{{ $record->person->present()->getTelephone }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.cellphone') }}</th>
                <td>{{ $record->person->present()->getCellphone }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.zipcode') }}</th>
                <td>{{ $record->person->present()->getZipcode }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.address') }}</th>
                <td>{{ $record->person->address }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.number') }}</th>
                <td>{{ $record->person->number }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.complement') }}</th>
                <td>{{ $record->person->complement }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.district') }}</th>
                <td>{{ $record->person->district }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.city') }}</th>
                <td>{{ $record->person->city }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.state') }}</th>
                <td>{{ $record->person->state }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.observation') }}</th>
                <td>{{ $record->observation }}</td>
            </tr>
            <tr>
                <th>{{ __('forms.partner') }}</th>
                <td>{{ $record->partner ? __('forms.yes') : __('forms.no') }}</td>
            </tr>
            <tr>
                <th>{{ __('actions.active') }}</th>
                <td>{{ $record->active ? __('forms.yes') : __('forms.no') }}</td>
            </tr>
            <tr>
                <th>{{ __('actions.created_at') }}</th>
                <td>{{ $record->created_at->format('d/m/Y H:i') }}</td>
            </tr>
            @if($record->deleted_at)
                <tr>
                    <th>{{ __('actions.deleted_at') }}</th>
                    <td>{{ $record->deleted_at->format('d/m/Y H:i') }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</fieldset>
