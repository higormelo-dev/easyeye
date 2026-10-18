<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Changelog;
use Illuminate\Http\{RedirectResponse, Request};
use Inertia\{Inertia, Response};

/**
 * Página "Novidades" do painel da clínica: o que mudou em cada versão
 * (resources/changelog/*.md, ver App\Support\Changelog). Qualquer usuário
 * autenticado do painel abre; o GET nunca escreve — a marca de "visto" é um
 * POST à parte, disparado pela própria página.
 */
class ChangelogController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Panel/News', [
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('news.breadcrumb'), 'url' => '#', 'active' => true],
            ],
            'version' => Changelog::latestVersion(),
            'html'    => Changelog::html(app()->getLocale()),
            'seenUrl' => route('panel.news.seen'),
            't'       => trans('news'),
        ]);
    }

    /** Marca a versão atual como vista pelo usuário (some o badge do menu em todos os dispositivos). */
    public function markSeen(Request $request): RedirectResponse
    {
        $version = Changelog::latestVersion();
        $user    = $request->user();

        if ($version !== null && $user !== null && $user->changelog_seen_version !== $version) {
            // Quietly: abrir a página não é evento de auditoria nem mexe em observers do usuário.
            $user->forceFill(['changelog_seen_version' => $version])->saveQuietly();
        }

        return back();
    }
}
