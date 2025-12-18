@extends('layouts.guest')

@section('content')
    <div class="card-body">
        {{ html()->form('POST', route('selectentity.store'))->class(['form-horizontal', 'form-material'])->id('loginform')->open() }}
            {{ html()->element('h3')->class('text-center mb-4')->text(__('auth.select_entity')) }}
            @error('entity_user_id')
                {{
                    html()->div()
                        ->class(['alert', 'alert-danger', 'mb-4', 'text-justify'])
                        ->text($message)
                }}
            @enderror
            {{
	            html()->div([
                    html()->select('entity_user_id')
                        ->class('form-select')
                        ->options($entities)
                        ->value(old('entity_user_id', null))
                        ->placeholder(__('actions.select'))
                ])->class(['col-12', 'form-group'])
            }}
            {{
                html()->div([
                    html()->div([
                        html()->button()->text(__('actions.select'))
                            ->class(['btn', 'w-100', 'btn-info', 'text-white', 'text-titlecase'])
                    ])
                ])->class(['col-12', 'form-group', 'text-center'])
            }}
        {{ html()->form()->close() }}

        {{ html()->form('POST', route('logout'))->class(['form-horizontal', 'form-material'])->open() }}
            {{
                html()->div()
                    ->class(['col-12', 'text-center'])
                    ->child(
                        html()->submit(__('Log Out'))->class(['btn', 'btn-link', 'text-dark'])
                    )
            }}
        {{ html()->form()->close() }}
    </div>
@endsection
