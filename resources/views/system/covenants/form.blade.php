<fieldset>
    <span class="badge bg-dark mb-4">* Campo obrigatório</span>
    <div class="row">
        {{
            html()->div([
                html()->label('Nome *', 'name')->class('form-label'),
                html()->text('name', $record->name ?? null)
                    ->class('form-control')
                    ->attributes([
                        'maxlength' => 200,
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-8 col-md-8 col-lg-8 col-xl-8'])
        }}
        {{
            html()->div([
                html()->label('Cor *', 'color')->class('form-label'),
				html()->element('br'),
                html()->text('color', $record->color ?? null)
                    ->class(['form-control', 'colorpicker'])
                    ->attributes([
                        'autocomplete' => 'off',
                        'data-colorpicker' => 'true'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
    </div>
    <div class="row">
        {{
            html()->div([
                html()->label('Cobrança *', 'table')->class('form-label'),
                html()->select('table', ['' => '', true => 'Sim', false => 'Não'], $record->table ?? null)
                    ->class('form-select')
                    ->attributes([
                        'autocomplete' => 'off'
                    ])
            ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
        }}
        @if(request()->routeIs('*.edit'))
            {{
                html()->div([
                    html()->label('Ativo *', 'active')->class('form-label'),
                    html()->select('active', ['' => '', true => 'Sim', false => 'Não'], $record->active ?? null)
                        ->class('form-select')
                        ->attributes([
                            'autocomplete' => 'off'
                        ])
                ])->class(['form-group', 'col-xs-12 col-sm-4 col-md-4 col-lg-4 col-xl-4'])
            }}
        @endif
    </div>
</fieldset>