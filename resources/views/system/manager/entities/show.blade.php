<fieldset>
    <div class="table-responsive">
        <table class="table">
            <tbody>
                @if($record->deleted_at)
                    <tr>
                        <th class="text-center bg-light" colspan="2">{{ __('actions.inactive') }}</th>
                    </tr>
                @endif
                <tr>
                    <th width="25%">{{ __('actions.code') }}</th>
                    <td>{{ $record->code }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.name') }}</th>
                    <td>{{ $record->name }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.subdomain') }}</th>
                    <td>{{ $record->subdomain ?: '-' }}</td>
                </tr>

                <tr><th class="bg-light" colspan="2"><small class="text-muted">{{ __('forms.email') }} / {{ __('forms.telephone') }}</small></th></tr>
                <tr>
                    <th>{{ __('forms.email') }}</th>
                    <td>{{ $record->email ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('forms.telephone') }}</th>
                    <td>{{ $record->telephone ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('forms.cellphone') }}</th>
                    <td>{{ $record->cellphone ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.website') }}</th>
                    <td>{{ $record->website ?: '-' }}</td>
                </tr>

                <tr><th class="bg-light" colspan="2"><small class="text-muted">{{ __('actions.sidemenu.settings') }}</small></th></tr>
                <tr>
                    <th>{{ __('actions.national_registration') }}</th>
                    <td>{{ $record->national_registration ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.state_registration') }}</th>
                    <td>{{ $record->state_registration ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.municipal_registration') }}</th>
                    <td>{{ $record->municipal_registration ?: '-' }}</td>
                </tr>

                <tr><th class="bg-light" colspan="2"><small class="text-muted">{{ __('forms.address') }}</small></th></tr>
                <tr>
                    <th>{{ __('forms.zipcode') }}</th>
                    <td>{{ $record->zipcode ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('forms.address') }}</th>
                    <td>
                        {{ $record->address ?: '-' }}
                        @if($record->number), {{ $record->number }}@endif
                        @if($record->complement) — {{ $record->complement }}@endif
                    </td>
                </tr>
                <tr>
                    <th>{{ __('forms.district') }}</th>
                    <td>{{ $record->district ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('forms.city') }} / {{ __('forms.state') }}</th>
                    <td>{{ $record->city ?: '-' }} / {{ $record->state ?: '-' }}</td>
                </tr>
                <tr>
                    <th>{{ __('forms.country') }}</th>
                    <td>{{ $record->country ?: '-' }}</td>
                </tr>

                <tr><th class="bg-light" colspan="2"></th></tr>
                <tr>
                    <th>{{ __('actions.active') }}</th>
                    <td>
                        @if($record->active)
                            <span class="badge bg-success">{{ __('forms.yes') }}</span>
                        @else
                            <span class="badge bg-secondary">{{ __('forms.no') }}</span>
                        @endif
                    </td>
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
    </div>
</fieldset>
