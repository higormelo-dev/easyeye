<fieldset>
    <div class="table-responsive">
        <table class="table">
            <tbody>
                @if($record->deleted_at)
                    <tr>
                        <th class="text-center bg-light" colspan="2">{{ __('actions.deleted_record') }}</th>
                    </tr>
                @endif
                <tr>
                    <th>{{ __('actions.code') }}</th>
                    <td>{{ $record->code }}</td>
                </tr>
                <tr>
                    <th>{{ __('forms.covenant_id') }}</th>
                    <td>{{ $record->covenant->name }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.skin') }}</th>
                    <td>{{ $record->skinType->name }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.iris') }}</th>
                    <td>{{ $record->irisType->name }}</td>
                </tr>
                <tr>
                    <th width="20%">{{ __('forms.full_name') }}</th>
                    <td>{{ $record->person->full_name }}</td>
                </tr>
                <tr>
                    <th width="20%">{{ __('forms.nickname') }}</th>
                    <td>{{ $record->person->nickname }}</td>
                </tr>
                <tr>
                    <th>{{ __('forms.national_registry') }}</th>
                    <td>{{ $record->person->present()->getNationalRegistry }}</td>
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
                    <td>{{ $record->person->email }}</td>
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
                @if($record->created_at && $record->updated_at && $record->created_at->format('d/m/Y H:i') !== $record->updated_at->format('d/m/Y H:i'))
                    <tr>
                        <th>{{ __('actions.updated_at') }}</th>
                        <td>{{ $record->updated_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endif
                @if($record->deleted_at)
                    <tr>
                        <th>{{ __('actions.deleted_at') }}</th>
                        <td>{{ $record->deleted_at->format('d/m/Y H:i') }}</td>
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</fieldset>