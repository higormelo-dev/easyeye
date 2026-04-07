@extends('layouts.guest')

@section('auth_layout', 'login-illustration')
@section('auth_hide_locale', '1')
@section('auth_hero_title', __('auth.sign_in'))
@section('auth_hero_image', asset('system/images/icons/log-illustration-img-01.png'))

@push('styles')
<style>
    .ee-login-brand {
        width: 190px;
    }

    .ee-login-card {
        border: 1px solid #d8dce7;
        border-radius: 8px;
        box-shadow: none;
    }

    .ee-login-card-body {
        padding: 1.6rem 1.5rem 1.35rem;
    }

    .ee-login-title {
        margin-bottom: .25rem;
        font-size: 1.9rem;
        font-weight: 700;
        line-height: 1.2;
        color: #0f172a;
    }

    .ee-login-subtitle {
        margin-bottom: .9rem;
        color: #64748b;
        font-size: .95rem;
    }

    .ee-login-form .form-label {
        font-size: .92rem;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: .45rem;
    }

    .ee-login-form .input-group-text {
        border: 1px solid #d6dbe7;
        border-right: 0;
        border-radius: 6px 0 0 6px;
        min-height: 40px;
    }

    .ee-login-form .form-control {
        border: 1px solid #d6dbe7;
        min-height: 40px;
        border-radius: 0 6px 6px 0;
        font-size: .92rem;
    }

    .ee-login-form .input-group .form-control.border-end-0 {
        border-right: 0;
        border-radius: 0;
    }

    .ee-login-form .input-group .btn {
        border: 1px solid #d6dbe7;
        border-left: 0;
        border-radius: 0 6px 6px 0;
        min-height: 40px;
    }

    .ee-login-forgot {
        color: #f23f4b;
        text-decoration: none;
        font-weight: 500;
        font-size: .92rem;
    }

    .ee-login-forgot:hover {
        color: #d92f39;
        text-decoration: underline;
    }

    .ee-login-submit {
        min-height: 40px;
        border-radius: 6px;
        background: #2e37a4;
        border-color: #2e37a4;
        font-weight: 600;
        font-size: .93rem;
    }

    .ee-login-submit:hover,
    .ee-login-submit:focus {
        background: #27308d;
        border-color: #27308d;
    }

    .ee-login-or {
        text-align: center;
        color: #64748b;
        font-size: .95rem;
        font-weight: 600;
        margin: .9rem 0;
    }

    .ee-social-list {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .55rem;
        margin-bottom: .85rem;
    }

    .ee-social-link {
        min-height: 40px;
        border-radius: 6px;
        border: 1px solid #d8dce7;
        background: #fff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: .2s ease-in-out;
    }

    .ee-social-link:hover {
        border-color: #2e37a4;
        box-shadow: 0 8px 18px rgba(46, 55, 164, .16);
    }

    .ee-login-register {
        text-align: center;
        color: #0f172a;
        font-size: .95rem;
        margin-bottom: 0;
    }

    .ee-login-register a {
        color: #2e37a4;
        text-decoration: none;
        font-weight: 600;
    }

    .ee-login-register a:hover {
        text-decoration: underline;
    }

    .ee-login-copy {
        text-align: center;
        margin-top: 1rem;
        margin-bottom: 0;
        color: #0f172a;
        font-size: .92rem;
        font-weight: 400;
    }

    @media (max-width: 991.98px) {
        .ee-login-card-body {
            padding: 1.2rem 1rem;
        }

        .ee-login-title {
            font-size: 1.6rem;
        }

        .ee-login-subtitle,
        .ee-login-register {
            font-size: .9rem;
        }

        .ee-login-copy {
            font-size: .85rem;
        }
    }

    /* ── Dark mode overrides for login page ── */
    [data-bs-theme="dark"] .ee-login-title    { color: #e2e8f0; }
    [data-bs-theme="dark"] .ee-login-subtitle { color: #94a3b8; }
    [data-bs-theme="dark"] .ee-login-copy     { color: #94a3b8; }
    [data-bs-theme="dark"] .ee-login-register { color: #cbd5e1; }

    [data-bs-theme="dark"] .ee-login-card { border-color: #2d3560; }

    [data-bs-theme="dark"] .ee-login-form .form-label { color: #cbd5e1; }

    [data-bs-theme="dark"] .ee-login-form .input-group-text {
        background-color: var(--bs-body-bg) !important;
        border-color: #3d4570;
        color: #94a3b8;
    }
    [data-bs-theme="dark"] .ee-login-form .form-control,
    [data-bs-theme="dark"] .ee-login-form .form-select { border-color: #3d4570; }

    [data-bs-theme="dark"] .ee-login-form .input-group .btn {
        border-color: #3d4570;
        color: #94a3b8;
    }

    [data-bs-theme="dark"] .ee-social-link {
        background: var(--bs-body-bg);
        border-color: #3d4570;
    }
    [data-bs-theme="dark"] .ee-social-link:hover { border-color: #2e37a4; }
</style>
@endpush

@section('content')
    <div class="text-center mb-4">
        <a href="{{ route('login') }}" class="d-inline-block">
            <img src="{{ asset('system/images/icons/logo.svg') }}" class="img-fluid ee-login-brand" alt="{{ config('app.name') }}">
        </a>
    </div>

    <div class="card ee-login-card">
        <div class="card-body ee-login-card-body ee-login-form">
            <div class="text-center mb-4">
                <h2 class="ee-login-title">{{ __('auth.sign_in') }}</h2>
                <p class="ee-login-subtitle">{{ __('auth.login_subtitle') }}</p>
            </div>

            <form method="POST" action="{{ route('login') }}" class="ee-login-form">
                @csrf

                @error('email')
                    <div class="alert alert-danger mb-3 py-2">
                        <i class="ti ti-alert-circle me-1"></i> {{ $message }}
                    </div>
                @enderror

                <div class="mb-3">
                    <label class="form-label">{{ __('actions.email') }}</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white">
                            <i class="ti ti-mail text-muted"></i>
                        </span>
                        <input type="email"
                               name="email"
                               class="form-control @error('email') is-invalid @enderror"
                               value="{{ old('email') }}"
                               required
                               autofocus
                               autocomplete="username"
                               placeholder="{{ __('actions.email') }}">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ __('actions.password') }}</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white">
                            <i class="ti ti-lock text-muted"></i>
                        </span>
                        <input type="password"
                               name="password"
                               id="password"
                               class="form-control border-end-0 @error('password') is-invalid @enderror"
                               required
                               autocomplete="current-password"
                               placeholder="************">
                        <button type="button"
                                class="btn btn-light bg-white border-start-0"
                                data-toggle-password="#password"
                                tabindex="-1"
                                aria-label="Toggle password visibility">
                            <i class="ti ti-eye-off"></i>
                        </button>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="form-check">
                        <input type="checkbox"
                               name="remember"
                               id="remember"
                               class="form-check-input"
                               {{ old('remember') ? 'checked' : '' }}>
                        <label class="form-check-label text-dark" for="remember">
                            {{ __('auth.remember_me') }}
                        </label>
                    </div>
                    <a href="{{ route('password.request') }}" class="ee-login-forgot">{{ __('auth.forget_password') }}</a>
                </div>

                <div class="d-grid mb-1">
                    <button type="submit" class="btn btn-primary ee-login-submit waves-effect waves-light">
                        {{ __('auth.sign_in') }}
                    </button>
                </div>
            </form>

            <div class="ee-login-or mb-1">&nbsp;</div>

            <p class="ee-login-register">
                {{ __('auth.no_account_yet') }}
                <a href="{{ route('register') }}">{{ __('auth.sign_up') }}</a>
            </p>

            @php $currentLocale = \App\Http\Middleware\SetLocale::getSupportedLocales()[app()->getLocale()] ?? null; @endphp
            <div class="border-top pt-3 mt-3 d-flex align-items-center justify-content-center gap-1">
                <div class="dropdown">
                    <button type="button"
                            class="btn btn-link btn-sm text-muted text-decoration-none dropdown-toggle"
                            data-bs-toggle="dropdown" aria-expanded="false"
                            style="font-size:.8rem;">
                        @if($currentLocale)
                            {{ $currentLocale['flag'] }} {{ $currentLocale['native'] }}
                        @else
                            {{ app()->getLocale() }}
                        @endif
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end" style="min-width:0">
                        @foreach(\App\Http\Middleware\SetLocale::getSupportedLocales() as $code => $locale)
                            <li>
                                <a href="{{ route('locale.switch', $code) }}"
                                   class="dropdown-item {{ app()->getLocale() === $code ? 'active fw-semibold' : '' }}"
                                   style="font-size:.85rem;">
                                    {{ $locale['flag'] }} {{ $locale['native'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <button type="button"
                        class="btn btn-link btn-sm text-muted p-1 ee-guest-dark-toggle"
                        title="{{ __('actions.switch') }}"
                        style="font-size:1rem; line-height:1;">
                    <i class="ti ti-moon ee-dark-icon-moon"></i>
                    <i class="ti ti-sun ee-dark-icon-sun"></i>
                </button>
            </div>
        </div>
    </div>

    <p class="ee-login-copy">
        Copyright &copy; {{ \Carbon\Carbon::now()->year }} - {{ config('app.name') }}.
    </p>
@endsection

@section('javascript')
    @vite(['resources/js/auth/login.js'])
@endsection
