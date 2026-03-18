@if(session()->has('impersonating'))
    @php $impersonating = session('impersonating'); @endphp
    <div class="d-flex align-items-center gap-2 px-4 py-2"
         style="background:#f59e0b;color:#1c1917;font-size:.875rem;border-radius:0;">
        <i class="bi bi-person-fill-gear fs-5"></i>
        <span>
            {{ __('actions.impersonate.banner_prefix', ['name' => $impersonating['original_user_name']]) }}
            <strong>{{ $impersonating['impersonated_user_name'] }}</strong>
            {{ __('actions.impersonate.banner_in') }}
            <strong>{{ $impersonating['impersonated_entity_name'] }}</strong>
        </span>
    </div>
@endif
