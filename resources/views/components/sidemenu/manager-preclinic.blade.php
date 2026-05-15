{{-- Manager Menu (Preclinic style) --}}
<li>
    <a href="{{ route('panel.manager.entities.index') }}" class="{{ request()->routeIs('panel.manager.entities.*') ? 'active' : '' }}">
        <i class="ti ti-building"></i><span>{{ __('actions.sidemenu.entities') }}</span>
    </a>
</li>
<li>
    <a href="{{ route('panel.manager.plans.index') }}" class="{{ request()->routeIs('panel.manager.plans.*') ? 'active' : '' }}">
        <i class="ti ti-package"></i><span>{{ __('actions.sidemenu.plans') }}</span>
    </a>
</li>
<li>
    <a href="{{ route('panel.manager.subscriptions.index') }}" class="{{ request()->routeIs('panel.manager.subscriptions.*') ? 'active' : '' }}">
        <i class="ti ti-file-invoice"></i><span>{{ __('actions.sidemenu.subscriptions') }}</span>
    </a>
</li>
<li>
    <a href="{{ route('panel.manager.gateways.index') }}" class="{{ request()->routeIs('panel.manager.gateways.*') ? 'active' : '' }}">
        <i class="ti ti-credit-card"></i><span>{{ __('actions.sidemenu.gateways') }}</span>
    </a>
</li>
<li>
    <a href="{{ route('panel.accesscontrol.users.index') }}" class="{{ request()->routeIs('panel.accesscontrol.users.*') ? 'active' : '' }}">
        <i class="ti ti-users-group"></i><span>{{ __('actions.sidemenu.users') }}</span>
    </a>
</li>
<li>
    <a href="{{ route('panel.manager.report-settings.index') }}" class="{{ request()->routeIs('panel.manager.report-settings.*') ? 'active' : '' }}">
        <i class="ti ti-file-description"></i><span>{{ __('actions.report_settings.title') }}</span>
    </a>
</li>
<li>
    <a href="{{ route('panel.manager.partners.index') }}" class="{{ request()->routeIs('panel.manager.partners.*') ? 'active' : '' }}">
        <i class="ti ti-affiliate"></i><span>{{ __('actions.sidemenu.partners') }}</span>
    </a>
</li>
