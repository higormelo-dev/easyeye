<li>
    <a class="waves-effect waves-dark" href="javascript:void(0);">
        <i class="fa fa-calendar"></i>
        <span class="hide-menu">{{ __('actions.sidemenu.schedules') }}</span>
    </a>
</li>
<li>
    <a class="waves-effect waves-dark" href="{{ route('panel.patients.index') }}">
        <i class="fa fa-users"></i>
        <span class="hide-menu">{{ __('actions.sidemenu.patients') }}</span>
    </a>
</li>
<li>
    <a class="waves-effect waves-dark"
       href="{{ route('panel.doctors.index') }}">
        <i class="fa fa-user-md"></i>
        <span class="hide-menu">{{ __('actions.sidemenu.doctors') }}</span>
    </a>
</li>
<li>
    <a class="has-arrow waves-effect waves-dark" href="javascript:void(0);" aria-expanded="false">
        <i class="fas fa-cash-register"></i><span class="hide-menu">Finaceiro</span>
    </a>
    <ul aria-expanded="false" class="collapse">
        <li><a href="javascript:void(0);">
                <span class="fa fa-building"></span> Fluxo de caixa</a>
        </li>
    </ul>
</li>
<li>
    <a class="has-arrow waves-effect waves-dark" href="javascript:void(0)" aria-expanded="false">
        <i class="fas fa-stream"></i><span class="hide-menu">Configuração</span>
    </a>
    <ul aria-expanded="false" class="collapse">
        <li>
            <a href="{{ route('panel.setting.covenants.index') }}">
                <span class="fa fa-list"></span> {{ __('actions.sidemenu.covenants') }}
            </a>
        </li>
        <li>
            <a href="{{ route('panel.setting.skintypes.index') }}">
                <span class="fa fa-list"></span> {{ __('actions.sidemenu.skintypes') }}
            </a>
        </li>
        <li>
            <a href="{{ route('panel.setting.iristypes.index') }}">
                <span class="fa fa-eye"></span> {{ __('actions.sidemenu.iristypes') }}
            </a>
        </li>
        <li>
            <a href="{{ route('panel.setting.visittypes.index') }}">
                <span class="fa fa-comments"></span> {{ __('actions.sidemenu.visittypes') }}
            </a>
        </li>
        <li>
            <a href="{{ route('panel.setting.additiontypes.index') }}">
                <span class="fa fa-comments"></span> {{ __('actions.sidemenu.additiontypes') }}
            </a>
        </li>
        <li>
            <a href="{{ route('panel.setting.surgerytypes.index') }}">
                <span class="fa fa-search"></span> {{ __('actions.sidemenu.surgerytypes') }}
            </a>
        </li>
        <li>
            <a class="has-arrow" href="javascript:void(0)" aria-expanded="false">
                <span class="fa fa-medkit"></span> Medicamento
            </a>
            <ul aria-expanded="false" class="collapse">
                <li>
                    <a href="javascript:void(0);"><span class="fa fa-list"></span> Tipo de apresentação</a>
                </li>
                <li><a href="javascript:void(0);"><span class="fa fa-medkit"></span> Medicamento</a></li>
            </ul>
        </li>
        <li>
            <a href="{{ route('panel.setting.covertesttypes.index') }}">
                <span class="fa fa-search"></span> {{ __('actions.sidemenu.colorvisiontypes') }}
            </a>
        </li>
        <li>
            <a href="{{ route('panel.setting.colorvisiontypes.index') }}">
                <span class="fa fa-search"></span> {{ __('actions.sidemenu.colorvisiontypes') }}
            </a>
        </li>
        <li>
            <a href="{{ route('panel.setting.visualacuitytypes.index') }}">
                <span class="fa fa-eye-slash"></span> {{ __('actions.sidemenu.visualacuitytypes') }}
            </a>
        </li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Lente</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Procedimento</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Tipo de PPC</a></li>
    </ul>
</li>
@if(session()->get('selected_entity_user_rule') === 'admin')
    <li>
        <a class="waves-effect waves-dark" href="{{ route('panel.accesscontrol.users.index') }}">
            <i class="fa fa-users-cog"></i>
            <span class="hide-menu">{{ __('actions.users') }}</span>
        </a>
    </li>
@endif
