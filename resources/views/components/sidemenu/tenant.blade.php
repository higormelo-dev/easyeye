@php use App\Enums\ClientRule; @endphp
<li>
    <a class="waves-effect waves-dark" href="{{ route('panel.schedules.index') }}">
        <i class="fa fa-calendar"></i>
        <span class="hide-menu">{{ __('actions.sidemenu.schedules') }}</span>
    </a>
</li>

@if(in_array(session('user_rule'), [ClientRule::Admin->value, ClientRule::Secretary->value], true))
    <li>
        <a class="waves-effect waves-dark" href="{{ route('panel.waiting-room.index') }}">
            <i class="fas fa-hourglass-half"></i>
            <span class="hide-menu">Sala de espera</span>
        </a>
    </li>
@endif
<li>
    <a class="waves-effect waves-dark" href="{{ route('panel.patients.index') }}">
        <i class="fa fa-users"></i>
        <span class="hide-menu">{{ __('actions.sidemenu.patients') }}</span>
    </a>
</li>

@if(in_array(session('selected_entity_user_rule'), [ClientRule::Admin->value, ClientRule::Secretary->value], true))
    <li>
        <a class="waves-effect waves-dark"
           href="{{ route('panel.doctors.index') }}">
            <i class="fa fa-user-md"></i>
            <span class="hide-menu">{{ __('actions.sidemenu.doctors') }}</span>
        </a>
    </li>
@endif

@if(in_array(session('selected_entity_user_rule'), [ClientRule::Admin->value, ClientRule::Financial->value], true))
    <li>
        <a class="has-arrow waves-effect waves-dark" href="javascript:void(0);" aria-expanded="false">
            <i class="fas fa-cash-register"></i><span class="hide-menu">Financeiro</span>
        </a>
        <ul aria-expanded="false" class="collapse">
            <li><a href="javascript:void(0);" class="link-underline link-underline-opacity-0">
                    <span class="fa fa-building"></span> Fluxo de caixa</a>
            </li>
        </ul>
    </li>
@endif

@if(session()->get('selected_entity_user_rule') === ClientRule::Admin->value)
    <li>
        <a class="has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false">
            <i class="fas fa-stream"></i><span class="hide-menu">Configuração</span>
        </a>
        <ul aria-expanded="false" class="collapse">
            <li>
                <a href="{{ route('panel.setting.covenants.index') }}" class="link-underline link-underline-opacity-0">
                    <span class="fa fa-list"></span> {{ __('actions.sidemenu.covenants') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.skintypes.index') }}" class="link-underline link-underline-opacity-0">
                    <span class="fa fa-list"></span> {{ __('actions.sidemenu.skintypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.iristypes.index') }}" class="link-underline link-underline-opacity-0">
                    <span class="fa fa-eye"></span> {{ __('actions.sidemenu.iristypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.visittypes.index') }}" class="link-underline link-underline-opacity-0">
                    <span class="fa fa-comments"></span> {{ __('actions.sidemenu.visittypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.additiontypes.index') }}"
                   class="link-underline link-underline-opacity-0">
                    <span class="fa fa-comments"></span> {{ __('actions.sidemenu.additiontypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.surgerytypes.index') }}"
                   class="link-underline link-underline-opacity-0">
                    <span class="fa fa-search"></span> {{ __('actions.sidemenu.surgerytypes') }}
                </a>
            </li>
            <li>
                <a class="has-arrow" href="javascript:void(0)" aria-expanded="false">
                    <span class="fa fa-medkit"></span> Medicamento
                </a>
                <ul aria-expanded="false" class="collapse">
                    <li>
                        <a href="javascript:void(0);" class="link-underline link-underline-opacity-0">
                            <span class="fa fa-list"></span> Tipo de apresentação
                        </a>
                    </li>
                    <li>
                        <a href="javascript:void(0);" class="link-underline link-underline-opacity-0">
                            <span class="fa fa-medkit"></span> Medicamento
                        </a>
                    </li>
                </ul>
            </li>
            <li>
                <a href="{{ route('panel.setting.covertesttypes.index') }}"
                   class="link-underline link-underline-opacity-0">
                    <span class="fa fa-search"></span> {{ __('actions.sidemenu.colorvisiontypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.colorvisiontypes.index') }}"
                   class="link-underline link-underline-opacity-0">
                    <span class="fa fa-search"></span> {{ __('actions.sidemenu.colorvisiontypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.visualacuitytypes.index') }}"
                   class="link-underline link-underline-opacity-0">
                    <span class="fa fa-eye-slash"></span> {{ __('actions.sidemenu.visualacuitytypes') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.lenses.index') }}" class="link-underline link-underline-opacity-0">
                    <span class="fa fa-search"></span> {{ __('actions.sidemenu.lenses') }}
                </a>
            </li>
            <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Procedimento</a></li>
            <li>
                <a href="{{ route('panel.setting.nearpointconvergences.index') }}"
                   class="link-underline link-underline-opacity-0">
                    <span class="fa fa-search"></span> {{ __('actions.sidemenu.nearpointconvergences') }}
                </a>
            </li>
            <li>
                <a href="{{ route('panel.setting.tv-displays.index') }}" class="link-underline link-underline-opacity-0">
                    <span class="fas fa-tv"></span> Displays de TV
                </a>
            </li>
        </ul>
    </li>
@endif

@if(session()->get('selected_entity_user_rule') === \App\Enums\ClientRule::Admin->value)
    <li>
        <a class="waves-effect waves-dark" href="{{ route('panel.accesscontrol.users.index') }}">
            <i class="fa fa-users-cog"></i>
            <span class="hide-menu">{{ __('actions.users') }}</span>
        </a>
    </li>
@endif
