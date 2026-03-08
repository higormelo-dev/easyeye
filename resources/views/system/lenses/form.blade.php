<fieldset>
    <span class="badge bg-dark mb-4">{{ __('forms.required_field') }}</span>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.name') . ' *', 'name')->class('form-label'),
                html()->text('name', $record->name ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 200,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
        {{
            html()->div([
                html()->label(__('forms.away') . ' *', 'away')->class('form-label'),
                html()->select('away', ['' => '', true => __('forms.yes'), false => __('forms.no')], $record->away ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-3 col-md-3 col-lg-3 col-xl-3'])
        }}
        {{
            html()->div([
                html()->label(__('forms.near') . ' *', 'near')->class('form-label'),
                html()->select('near', ['' => '', true => __('forms.yes'), false => __('forms.no')], $record->near ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-3 col-md-3 col-lg-3 col-xl-3'])
        }}
    </div>
     @if(request()->routeIs('*.edit'))
        <div class="row">
            {{
                html()->div([
                    html()->label(__('forms.active') . ' *', 'active')->class('form-label'),
                    html()->select('active', ['' => '', true => __('forms.yes'), false => __('forms.no')], $record->active ?? null)
                        ->class('form-select')
                        ->attributes([
                            'autocomplete' => 'off'
                        ])
                ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
            }}
        </div>
    @endif
</fieldset>