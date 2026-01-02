<li>
    <a class="waves-effect waves-dark" href="javascript:void(0);">
        <i class="fa fa-calendar"></i>
        <span class="hide-menu"> Agenda </span>
    </a>
</li>
<li>
    <a class="waves-effect waves-dark" href="javascript:void(0);">
        <i class="fa fa-users"></i>
        <span class="hide-menu"> Paciente </span>
    </a>
</li>
<li>
    <a class="waves-effect waves-dark"
       href="{{ route('panel.doctors.index') }}">
        <i class="fa fa-user-md"></i>
        <span class="hide-menu">Médico</span>
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
        <li><a href="javascript:void(0);"><span class="fa fa-list"></span> Convênio</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-list"></span> Tipo de cútis</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-eye"></span> Tipo de íris</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-comments"></span> Tipo de visita</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-comments"></span> Tipo de adição</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Tipo de cirurgia</a></li>
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
            <a href="javascript:void(0);"><span class="fa fa-search"></span> Tipo de cover test</a>
        </li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Tipo de visão cromática</a></li>
        <li>
            <a href="javascript:void(0);"><span class="fa fa-eye-slash"></span> Acuidade visual</a>
        </li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Lente</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Procedimento</a></li>
        <li><a href="javascript:void(0);"><span class="fa fa-search"></span> Tipo de PPC</a></li>
    </ul>
</li>
@if(session()->get('selected_entity_user_rule') === 'admin')
    <li>
        <a class="waves-effect waves-dark" href="{{ route('panel.accesscontrol.users.index') }}">
            <i class="fa fa-users-cog"></i><span class="hide-menu">Usuários</span>
        </a>
    </li>
@endif
