@php use App\Enums\ClientRule; @endphp

{{-- Agenda --}}
<li>
    <a href="{{ route('panel.schedules.index') }}" class="{{ request()->routeIs('panel.schedules.*') ? 'active' : '' }}">
        <i class="ti ti-calendar"></i><span>{{ __('actions.sidemenu.schedules') }}</span>
    </a>
</li>

{{-- Pacientes --}}
<li>
    <a href="{{ route('panel.patients.index') }}" class="{{ request()->routeIs('panel.patients.*') ? 'active' : '' }}">
        <i class="ti ti-users"></i><span>{{ __('actions.sidemenu.patients') }}</span>
    </a>
</li>

{{-- Eye Images --}}
<li>
    <a href="{{ route('panel.eye-images.index') }}" class="{{ request()->routeIs('panel.eye-images.*') ? 'active' : '' }}">
        <i class="ti ti-eye"></i><span>{{ __('dashboard.module_eye_images') }}</span>
    </a>
</li>

{{-- Médicos --}}
@if(in_array(session('selected_entity_user_rule'), [ClientRule::Admin->value, ClientRule::Secretary->value], true))
    <li>
        <a href="{{ route('panel.doctors.index') }}" class="{{ request()->routeIs('panel.doctors.*') ? 'active' : '' }}">
            <i class="ti ti-stethoscope"></i><span>{{ __('actions.sidemenu.doctors') }}</span>
        </a>
    </li>
@endif

{{-- Financeiro --}}
@if(in_array(session('selected_entity_user_rule'), [ClientRule::Admin->value, ClientRule::Financial->value], true))
    @php $financialOpen = request()->routeIs('panel.financial.*'); @endphp
    <li class="submenu">
        <a href="javascript:void(0);" class="{{ $financialOpen ? 'subdrop active' : '' }}">
            <i class="ti ti-cash-register"></i><span>{{ __('actions.sidemenu.financial') }}</span>
            <span class="menu-arrow"></span>
        </a>
        <ul style="{{ $financialOpen ? 'display: block;' : '' }}">
            <li>
                <a href="{{ route('panel.financial.bi.index') }}"
                   class="{{ request()->routeIs('panel.financial.bi.*') ? 'active' : '' }}">
                    <i class="ti ti-layout-dashboard"></i> Dashboard Gerencial
                </a>
            </li>
            <li>
                <a href="{{ route('panel.financial.cash-flow.index') }}"
                   class="{{ request()->routeIs('panel.financial.cash-flow.*') ? 'active' : '' }}">
                    <i class="ti ti-building-bank"></i> {{ __('actions.sidemenu.cash_flow') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.financial.billing.index') }}"
                   class="{{ request()->routeIs('panel.financial.billing.*') ? 'active' : '' }}">
                    <i class="ti ti-file-invoice"></i> {{ __('actions.sidemenu.tiss_billing') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.financial.tiss.glosas.index') }}"
                   class="{{ request()->routeIs('panel.financial.tiss.glosas.*') ? 'active' : '' }}">
                    <i class="ti ti-gavel"></i> Glosas TISS
                </a>
            </li>
            <li>
                <a href="{{ route('panel.financial.reports.cash-flow') }}"
                   class="{{ request()->routeIs('panel.financial.reports.cash-flow*') ? 'active' : '' }}">
                    <i class="ti ti-chart-arcs"></i> Rel. Fluxo de Caixa
                </a>
            </li>
            <li>
                <a href="{{ route('panel.financial.reports.covenants') }}"
                   class="{{ request()->routeIs('panel.financial.reports.covenants*') ? 'active' : '' }}">
                    <i class="ti ti-report-money"></i> Rel. Faturamento
                </a>
            </li>
        </ul>
    </li>
@endif

{{-- Relatórios — apenas admin e financial, espelhando entity.role:admin,financial da rota --}}
@if(in_array(session('selected_entity_user_rule'), [ClientRule::Admin->value, ClientRule::Financial->value], true))
    <li class="menu-title"><span>{{ __('actions.sidemenu.reports') ?? 'Relatórios' }}</span></li>
    <li>
        <a href="{{ route('panel.reports.index') }}" class="{{ request()->routeIs('panel.reports.*') ? 'active' : '' }}">
            <i class="ti ti-chart-bar"></i><span>Relatórios</span>
        </a>
    </li>
@endif

{{-- Configuração --}}
@if(session()->get('selected_entity_user_rule') === ClientRule::Admin->value)
    <li class="menu-title"><span>Configuração</span></li>
    <li class="submenu">
        <a href="javascript:void(0);" class="{{ request()->routeIs('panel.setting.*') ? 'active subdrop' : '' }}">
            <i class="ti ti-settings"></i><span>Configuração</span>
            <span class="menu-arrow"></span>
        </a>
        <ul style="{{ request()->routeIs('panel.setting.*') ? 'display:block;' : '' }}">
            <li>
                <a href="{{ route('panel.setting.covenants.index') }}" class="{{ request()->routeIs('panel.setting.covenants.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.covenants') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.skintypes.index') }}" class="{{ request()->routeIs('panel.setting.skintypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.skintypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.iristypes.index') }}" class="{{ request()->routeIs('panel.setting.iristypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.iristypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.visittypes.index') }}" class="{{ request()->routeIs('panel.setting.visittypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.visittypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.additiontypes.index') }}" class="{{ request()->routeIs('panel.setting.additiontypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.additiontypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.surgerytypes.index') }}" class="{{ request()->routeIs('panel.setting.surgerytypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.surgerytypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.covertesttypes.index') }}" class="{{ request()->routeIs('panel.setting.covertesttypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.colorvisiontypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.colorvisiontypes.index') }}" class="{{ request()->routeIs('panel.setting.colorvisiontypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.colorvisiontypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.visualacuitytypes.index') }}" class="{{ request()->routeIs('panel.setting.visualacuitytypes.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.visualacuitytypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.lenses.index') }}" class="{{ request()->routeIs('panel.setting.lenses.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.lenses') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.nearpointconvergences.index') }}" class="{{ request()->routeIs('panel.setting.nearpointconvergences.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.nearpointconvergences') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.report-settings.index') }}" class="{{ request()->routeIs('panel.setting.report-settings.*') ? 'active' : '' }}">
                    {{ __('actions.report_settings.title') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.gateways.index') }}" class="{{ request()->routeIs('panel.setting.gateways.*') ? 'active' : '' }}">
                    Gateways de Pagamento
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.resources.index') }}" class="{{ request()->routeIs('panel.setting.resources.*') ? 'active' : '' }}">
                    {{ __('actions.sidemenu.resources') ?? 'Recursos' }}
                </a>
            </li>
        </ul>
    </li>

    {{-- Controle de Acesso --}}
    <li class="menu-title"><span>Controle de Acesso</span></li>
    <li>
        <a href="{{ route('panel.accesscontrol.users.index') }}" class="{{ request()->routeIs('panel.accesscontrol.users.*') ? 'active' : '' }}">
            <i class="ti ti-users-group"></i><span>{{ __('actions.users') }}</span>
        </a>
    </li>
@endif
