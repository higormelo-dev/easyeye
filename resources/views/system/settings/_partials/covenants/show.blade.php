<tr>
    <th width="20%">{{ __("forms.color") }}</th>
    <td>
        <span class="badge bg-success" style="background-color: {{ $record->color }} !important;">&nbsp;&nbsp;&nbsp;&nbsp;</span>
    </td>
</tr>
<tr>
    <th>{{ __("forms.table") }}</th>
    <td>{{ $record->table ? __("forms.yes") : __("forms.no") }}</td>
</tr>
