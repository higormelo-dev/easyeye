<fieldset>
    <span class="badge bg-dark mb-4">{{ __('forms.required_field') }}</span>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.full_name') . ' *', 'full_name')->class('form-label'),
                html()->text('full_name', $record->person->full_name ?? null)
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
                html()->label(__('forms.nickname'), 'nickname')->class('form-label'),
                html()->text('nickname', $record->person->nickname ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-8 col-md-8 col-lg-8 col-xl-8'])
        }}
        {{
            html()->div([
                html()->label(__('forms.national_registry') . ' *', 'national_registry')->class('form-label'),
                html()->text('national_registry', $record->person->national_registry ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.covenant_id') . ' *', 'covenant_id')->class('form-label'),
                html()->select('covenant_id', ['' => ''] + $covenants, $record->covenant_id ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
        {{
            html()->div([
                html()->label(__('forms.card_number'), 'card_number')->class('form-label'),
                html()->text('card_number', $record->card_number ?? null)
                    ->class('form-control')
                    ->when(
                        empty($record) || !$record->covenant_id || $record->covenant->name === 'Particular',
                        fn($input) => $input->attribute('disabled', true)
                    )
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off',
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.skin_id'), 'skin_id')->class('form-label'),
                html()->select('skin_id', ['' => ''] + $skinTypes, $record->skin_id ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
        {{
            html()->div([
                html()->label(__('forms.iris_id'), 'iris_id')->class('form-label'),
                html()->select('iris_id', ['' => ''] + $irisTypes, $record->iris_id ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.birth_date'), 'birth_date')->class('form-label'),
                html()->date('birth_date', $record->person->birth_date ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off',
                        'min' => '1900-01-01',
                        'max' => date('Y-m-d')
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
        {{
            html()->div([
                html()->label(__('forms.gender') . ' *', 'gender')->class('form-label'),
                html()->select('gender', ['' => ''] + $genders, $record->person->gender ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
        {{
            html()->div([
                html()->label(__('forms.marital_status'), 'marital_status')->class('form-label'),
                html()->select('marital_status', ['' => ''] + $maritalStatuses, $record->person->marital_status ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.email') . ' *', 'email')->class('form-label'),
                html()->email('email', $record->person->email ?? null)
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
                html()->label(__('forms.mother_name'), 'mother_name')->class('form-label'),
                html()->text('mother_name', $record->person->mother_name ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
        {{
            html()->div([
                html()->label(__('forms.father_name'), 'father_name')->class('form-label'),
                html()->text('father_name', $record->person->father_name ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.state_registry'), 'state_registry')->class('form-label'),
                html()->text('state_registry', $record->person->state_registry ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-3 col-md-3 col-lg-3 col-xl-3'])
        }}
        {{
            html()->div([
                html()->label(__('forms.state_registry_agency'), 'state_registry_agency')->class('form-label'),
                html()->text('state_registry_agency', $record->person->state_registry_agency ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 100,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-3 col-md-3 col-lg-3 col-xl-3'])
        }}
        {{
            html()->div([
                html()->label(__('forms.state_registry_initial'), 'state_registry_initial')->class('form-label'),
                html()->select('state_registry_initial', ['' => ''] + $statesOfBrazil, $record->person->state_registry_initial ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-3 col-md-3 col-lg-3 col-xl-3'])
        }}
        {{
            html()->div([
                html()->label(__('forms.state_registry_date'), 'state_registry_date')->class('form-label'),
                html()->date('state_registry_date', $record->person->state_registry_date ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off',
                        'min' => '1900-01-01',
                        'max' => date('Y-m-d')
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-3 col-md-3 col-lg-3 col-xl-3'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.telephone'), 'telephone')->class('form-label'),
                html()->text('telephone', $record->person->telephone ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
        {{
            html()->div([
                html()->label(__('forms.cellphone') . ' *', 'cellphone')->class('form-label'),
                html()->div([
                    html()->text('cellphone', $record->person->cellphone ?? null)
                        ->class('form-control')
                        ->attributes([
                            'autocomplete' => 'off'
                        ]),
                    html()->select('whatsapp', ['' => '', true => __('forms.is_whatsapp'), false => __('forms.is_not_whatsapp')], $record->person->whatsapp ?? null)
                        ->class('form-select')
                        ->attributes([
                            'autocomplete' => 'off'
                        ])
                ])->class('input-group')
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.zipcode'), 'zipcode')->class('form-label'),
                html()->text('zipcode', $record->person->zipcode ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
        {{
            html()->div([
                html()->label(__('forms.address'), 'address')->class('form-label'),
                html()->text('address', $record->person->address ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-6 col-md-6 col-lg-6 col-xl-6'])
        }}
        {{
            html()->div([
                html()->label(__('forms.number'), 'number')->class('form-label'),
                html()->text('number', $record->person->number ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-2 col-md-2 col-lg-2 col-xl-2'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.complement'), 'complement')->class('form-label'),
                html()->text('complement', $record->person->complement ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-12 col-md-12 col-lg-12 col-xl-12'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label(__('forms.district'), 'district')->class('form-label'),
                html()->text('district', $record->person->district ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
        {{
            html()->div([
                html()->label(__('forms.city'), 'city')->class('form-label'),
                html()->text('city', $record->person->city ?? null)
                    ->class('form-control')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
        {{
            html()->div([
                html()->label(__('forms.state'), 'state')->class('form-label'),
                html()->select('state', ['' => ''] + $statesOfBrazil, $record->person->state ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
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