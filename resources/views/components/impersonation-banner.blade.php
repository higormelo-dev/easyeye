@if(session()->has('impersonating'))
    @php
        $impersonating = session('impersonating');
    @endphp
    <div class="impersonation-banner d-flex align-items-center justify-content-between px-3 py-2"
         style="background:#f59e0b;color:#1c1917;position:sticky;top:0;z-index:1055;font-size:.875rem;">

        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-person-fill-gear fs-5"></i>
            <span>
                {{ __('actions.impersonate.banner_prefix') }}
                <strong>{{ $impersonating['impersonated_user_name'] }}</strong>
                {{ __('actions.impersonate.banner_in') }}
                <strong>{{ $impersonating['impersonated_entity_name'] }}</strong>
            </span>
        </div>

        <form method="POST"
              action="{{ route('panel.manager.impersonate.destroy') }}"
              class="mb-0">
            @csrf
            @method('DELETE')
            <button type="submit"
                    class="btn btn-sm btn-dark d-flex align-items-center gap-1">
                <i class="bi bi-arrow-return-left"></i>
                {{ __('actions.impersonate.exit') }}
            </button>
        </form>
    </div>
@endif
