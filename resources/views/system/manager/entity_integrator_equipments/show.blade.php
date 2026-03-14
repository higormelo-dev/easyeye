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
                    <th width="20%">{{ __('actions.code') }}</th>
                    <td>{{ $record->code }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.name') }}</th>
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
                    <th>{{ __('actions.serial_number') }}</th>
                    <td>{{ $record->serial_number }}</td>
                </tr>
                <tr>
                    <th>{{ __('actions.active') }}</th>
                    <td>
                        @if($record->active)
                            <span class="badge bg-success">{{ __('actions.yes') }}</span>
                        @else
                            <span class="badge bg-dark">{{ __('actions.no') }}</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th>{{ __('actions.created_at') }}</th>
                    <td>{{ $record->created_at?->format('d/m/Y H:i') }}</td>
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
