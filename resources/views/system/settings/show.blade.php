<fieldset>
    <table class="table">
        <tbody>
            @if($record->deleted_at)
                <tr>
                    <th class="text-center bg-light" colspan="2">{{ __("actions.inactive") }}</th>
                </tr>
            @endif
            <tr>
                <th width="20%">{{ __("actions.code") }}</th>
                <td>{{ $record->code }}</td>
            </tr>
            <tr>
                <th>{{ __("actions.name") }}</th>
                <td>{{ $record->name }}</td>
            </tr>

            @includeIf("system.settings._partials." . $viewSlot . ".show", ["record" => $record])

            <tr>
                <th>{{ __("actions.active") }}</th>
                <td>{{ $record->active ? __("forms.yes") : __("forms.no") }}</td>
            </tr>
            <tr>
                <th>{{ __("actions.created_at") }}</th>
                <td>{{ $record->created_at->format("d/m/Y H:i") }}</td>
            </tr>
            @if($record->deleted_at)
                <tr>
                    <th>{{ __("actions.deleted_at") }}</th>
                    <td>{{ $record->deleted_at->format("d/m/Y H:i") }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</fieldset>
