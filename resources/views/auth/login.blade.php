@extends('layouts.guest')

@section('content')
    <div class="card-body">
        {{ html()->form('POST', route('login'))->class(['form-horizontal', 'form-material'])->id('loginform')->open() }}
            {{ html()->element('h3')->class('text-center m-b-20')->text(__('auth.sign_in')) }}
            {{
	            html()->div([
                    html()->text('email')->class('form-control')
                    ->attributes(['required', 'autofocus', 'autocomplete' => 'username'])
                    ->placeholder(__('actions.user'))
                ])->class(['col-12', 'form-group'])
            }}
            {{
                html()->div([
                    html()->password('password')->class('form-control')
                    ->attributes(['required'])
                    ->placeholder(__('actions.password'))
                ])->class(['col-12', 'form-group'])
            }}
            {{
                html()->div()
                    ->class(['form-group', 'row'])
                    ->child(
                        html()->div()
                            ->class(['col-md-12'])
                            ->child(
                                html()->div()
                                    ->class(['d-flex', 'no-block', 'align-items-center'])
                                    ->children([
                                        html()->div()
                                            ->class(['form-check'])
                                            ->children([
                                                html()->checkbox('remember')
                                                    ->class(['form-check-input'])
                                                    ->id('customCheck1'),
                                                html()->label(__('auth.remember_me'))
                                                    ->class(['form-check-label'])
                                                    ->for('customCheck1')
                                            ]),
                                        html()->div()
                                            ->class(['ms-auto'])
                                            ->child(
                                                html()->a(route('password.request'), __('auth.forgot-password'))
                                                    ->id('to-recover')
                                                    ->class(['text-muted'])
                                                    ->html('<i class="fas fa-lock m-r-5"></i> ' . __('auth.forget_password'))
                                            )
                                    ])
                            )
                    )
            }}
            {{
                html()->div([
                    html()->div([
                        html()->button()->text(__('auth.sign_in'))
                            ->class(['btn', 'w-100', 'btn-info', 'text-white', 'text-uppercase'])
                    ])->class(['col-xs-12', 'p-b-20'])
                ])->class(['col-12', 'form-group', 'text-center'])
            }}
            {{
                html()->div()
                    ->class(['form-group', 'm-b-0'])
                    ->child(
                        html()->div()
                            ->class(['col-sm-12', 'text-center'])
                            ->html(__('actions.dont_have_account') . ' ' .
                                   html()->a(route('register'))
                                       ->class(['text-info', 'm-l-5'])
                                       ->child(html()->element('strong')->text(__('actions.new_account')))
                            )
                    )
            }}
        {{ html()->form()->close() }}
    </div>
@endsection





{{--<x-guest-layout>--}}
{{--    <!-- Session Status -->--}}
{{--    <x-auth-session-status class="mb-4" :status="session('status')" />--}}

{{--    <form method="POST" action="{{ route('login') }}">--}}
{{--        @csrf--}}

{{--        <!-- Email Address -->--}}
{{--        <div>--}}
{{--            <x-input-label for="email" :value="__('Email')" />--}}
{{--            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />--}}
{{--            <x-input-error :messages="$errors->get('email')" class="mt-2" />--}}
{{--        </div>--}}

{{--        <!-- Password -->--}}
{{--        <div class="mt-4">--}}
{{--            <x-input-label for="password" :value="__('Password')" />--}}

{{--            <x-text-input id="password" class="block mt-1 w-full"--}}
{{--                            type="password"--}}
{{--                            name="password"--}}
{{--                            required autocomplete="current-password" />--}}

{{--            <x-input-error :messages="$errors->get('password')" class="mt-2" />--}}
{{--        </div>--}}

{{--        <!-- Remember Me -->--}}
{{--        <div class="block mt-4">--}}
{{--            <label for="remember_me" class="inline-flex items-center">--}}
{{--                <input id="remember_me" type="checkbox" class="rounded dark:bg-gray-900 border-gray-300 dark:border-gray-700 text-indigo-600 shadow-sm focus:ring-indigo-500 dark:focus:ring-indigo-600 dark:focus:ring-offset-gray-800" name="remember">--}}
{{--                <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ __('Remember me') }}</span>--}}
{{--            </label>--}}
{{--        </div>--}}

{{--        <div class="flex items-center justify-end mt-4">--}}
{{--            @if (Route::has('password.request'))--}}
{{--                <a class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}">--}}
{{--                    {{ __('Forgot your password?') }}--}}
{{--                </a>--}}
{{--            @endif--}}

{{--            <x-primary-button class="ms-3">--}}
{{--                {{ __('Log in') }}--}}
{{--            </x-primary-button>--}}
{{--        </div>--}}
{{--    </form>--}}
{{--</x-guest-layout>--}}
