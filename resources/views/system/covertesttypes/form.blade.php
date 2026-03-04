<fieldset>
    <span class="badge bg-dark mb-4">{{ __('forms.required_field') }}</span>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.abbreviation'), 'abbreviation')->class('form-label'),
                html()->text('abbreviation', $record->abbreviation ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 200,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
        {{
            html()->div([
                html()->label(__('forms.name'), 'name')->class('form-label'),
                html()->text('name', $record->name ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 200,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-8 col-md-8 col-lg-8 col-xl-8'])
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