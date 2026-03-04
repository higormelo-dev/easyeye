<fieldset>
    <span class="badge bg-dark mb-4">{{ __('forms.required_field') }}</span>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.integration_name') . ' *', 'name')->class('form-label'),
                html()->text('name', $record->name ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'required' => true
                    ])
            ])->class(['form-group', 'col'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.ip'), 'ip')->class('form-label'),
                html()->text('ip', $record->ip ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 15,
                        'pattern' => '^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$'
                    ])
                    ->placeholder('192.168.1.1')
            ])->class(['form-group', 'col'])
        }}
        {{
            html()->div([
                html()->label(__('forms.mac'), 'mac')->class('form-label'),
                html()->text('mac', $record->mac ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 17,
                        'pattern' => '^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$',
                        'title' => 'Digite um endereço MAC válido (ex: 00:1B:44:11:3A:B7)',
                        'style' => 'text-transform: uppercase;'
                    ])
                    ->placeholder('00:1B:44:11:3A:B7')
            ])->class(['form-group', 'col'])
        }}


    </div>
</fieldset>