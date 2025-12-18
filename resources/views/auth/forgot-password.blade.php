@extends('layouts.guest')

@section('content')
    <div class="card-body">
        {{ html()->form('POST', route('password.email'))->class(['form-horizontal', 'form-material'])->id('loginform')->open() }}
            {{ html()->element('h3')->class('text-center m-b-20')->text(__('Reset Password')) }}
            {{
                html()->div()
                    ->class(['alert', 'alert-info', 'text-justify'])
                    ->children([
						html()->element('p')->class('mb-0')
						    ->text(__('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.')),
                    ])
            }}
            @error('email')
                {{
                    html()->div()
                        ->class(['alert', 'alert-danger', 'mb-4', 'text-justify'])
                        ->text($message)
                }}
            @enderror
            {{
                html()->div([
                    html()->text('email')->class('form-control')
                    ->value(old('email', null))
                    ->attributes(['autofocus'])
                    ->placeholder(__('actions.email'))
                ])->class(['col-12', 'form-group'])
            }}
            {{
                html()->div([
                    html()->div([
                        html()->button()->text(__('Email Password Reset Link'))
                            ->class(['btn', 'w-100', 'btn-info', 'text-white', 'text-titlecase'])
                    ])
                ])->class(['col-12', 'form-group', 'text-center'])
            }}
            {{
                html()->div()
                    ->class(['col-12', 'text-center', 'm-b-0'])
                    ->child(
                        html()->a(route('login'))
                           ->class(['text-dark', 'm-l-5'])
                           ->child(html()->element('strong')->text(__('auth.back_to_login')))
                    )
            }}
        {{ html()->form()->close() }}
    </div>
@endsection
