<fieldset>
    <span class="badge bg-dark mb-4">{{ __('forms.required_field') }}</span>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.name') . ' *', 'name')->class('form-label'),
                html()->text('name', $record->user->name ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.email') . ' *', 'email')->class('form-label'),
                html()->email('email', $record->user->email ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col'])
        }}
    </div>
    @if(request()->routeIs('*.create'))
        <div class="row">
            {{
                html()->div([
                    html()->label(__('forms.password') . ' *', 'password')->class('form-label'),
                    html()->password('password')
                        ->id('password')
                        ->class('form-control')
                        ->attributes([
                            'maxlength' => 100,
                            'autocomplete' => 'new-password'
                        ])
                ])->class(['form-group', 'col'])
            }}
        </div>
        <div class="row">
            {{
                html()->div([
                    html()->label(__('forms.password_confirmation') . ' *', 'password_confirmation')->class('form-label'),
                    html()->password('password_confirmation')
                        ->id('password_confirmation')
                        ->class('form-control')
                        ->attributes([
                            'maxlength' => 100,
                            'autocomplete' => 'new-password'
                        ])
                ])->class(['form-group', 'col'])
            }}
        </div>
    @endif
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.rule') . ' *', 'rule')->class('form-label'),
                html()->select('rule', $roles, $record->rule ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col'])
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
                ])->class(['form-group', 'col'])
            }}
        </div>
    @endif
</fieldset>