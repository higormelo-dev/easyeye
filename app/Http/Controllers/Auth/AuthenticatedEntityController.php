<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SelectEntityRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\{Inertia, Response};
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class AuthenticatedEntityController extends Controller
{
    public function create(): Response
    {
        $entities    = [];
        $entityUsers = Auth::user()->entityUsers->where('active', true);
        $entityUsers->load('entity');

        if (count($entityUsers) > 1) {
            foreach ($entityUsers as $entityUser) {
                $suffix                    = app()->environment(['local', 'testing']) && $entityUser->rule === 'admin' ? ' *' : '';
                $entities[$entityUser->id] = trim($entityUser->entity->name . $suffix);
            }
        }

        return Inertia::render('Auth/SelectEntity', [
            'appName'  => config('app.name', 'EasyEye'),
            't'        => trans('auth'),
            'entities' => $entities,
        ])->rootView('guest-app');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(SelectEntityRequest $request): HttpResponse
    {
        // Achado de segurança (auditoria manager.php — ROLE_BYPASS CRITICAL):
        // faltava active=true aqui — permitia reselecionar um vínculo
        // entity_users desativado (ex.: staff SaaS revogado sem soft-delete)
        // e obter sessão administrativa plena de novo. Mesmo padrão de
        // canAccessEntity()/getRuleInEntity() (App\Traits\HasEntityRoles).
        $entityUser = Auth::user()->entityUsers()->with('entity')
            ->where('active', true)
            ->find($request->entity_user_id);

        abort_unless($entityUser, 403, 'Este vínculo não está mais ativo.');

        session([
            'selected_entity_user_id'   => $entityUser->id,
            'selected_entity_user_rule' => $entityUser->rule,
            'selected_entity_id'        => $entityUser->entity->id,
            'selected_entity_is_client' => $entityUser->entity->is_client,
            'user_rule'                 => $entityUser->rule,
        ]);

        if ($entityUser->rule === 'doctor') {
            session(['selected_entity_doctor_id' => $entityUser->doctor->id]);
        }

        // Usuários SaaS (non-client) não devem ser redirecionados para URLs que
        // ficaram salvas como url.intended durante uma sessão de impersonação expirada.
        if (! session('selected_entity_is_client', true)) {
            session()->forget('url.intended');

            return $this->redirectToPanelDashboard($request);
        }

        // Full reload obrigatório: a seleção de entidade roda no rootView
        // guest-app (sem @routes/vendor.js). Navegação SPA para o painel
        // montaria o AppLayout sem Ziggy/jQuery — menu mobile morto.
        $intendedUrl = session()->pull('url.intended', route('panel.dashboard'));

        if ($request->header('X-Inertia')) {
            return Inertia::location($intendedUrl);
        }

        return redirect()->to($intendedUrl);
    }

    private function redirectToPanelDashboard(Request $request): HttpResponse
    {
        $dashboardUrl = route('panel.dashboard');

        if ($request->header('X-Inertia')) {
            return Inertia::location($dashboardUrl);
        }

        return redirect()->to($dashboardUrl);
    }
}
