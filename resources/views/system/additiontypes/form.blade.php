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
            ])->class(['form-group', 'col'])
        }}
    </div>
     @if(request()->routeIs('*.edit'))
        <div class="row">
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
        </div>
    @endif
</fieldset>