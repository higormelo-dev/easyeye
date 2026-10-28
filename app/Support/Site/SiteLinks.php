<?php

declare(strict_types=1);

namespace App\Support\Site;

use Illuminate\Support\Facades\Route;

/**
 * Links e contatos compartilhados pelas páginas públicas do site (navbar,
 * rodapé, Home e páginas legais).
 *
 * Páginas institucionais só aparecem no rodapé quando a rota nomeada existe:
 * antes o rodapé apontava para 10 URLs fixas que respondiam 404. Para publicar
 * uma página nova basta registrar a rota com o nome de OPTIONAL_PAGES.
 */
final class SiteLinks
{
    /** chave da prop => nome da rota */
    private const OPTIONAL_PAGES = [
        'help'     => 'site.help',
        'status'   => 'site.status',
        'about'    => 'site.about',
        'blog'     => 'site.blog',
        'partners' => 'site.partners',
        'careers'  => 'site.careers',
        'privacy'  => 'site.privacy',
        'terms'    => 'site.terms',
        'lgpd'     => 'site.lgpd',
    ];

    /**
     * @return array<string, string|null> null = página indisponível (não renderizar o link)
     */
    public static function routes(): array
    {
        $routes = [
            'siteHome' => route('site.home'),
            'register' => route('register'),
            'go'       => route('go'),
            // Swagger dos integradores: sem DOCS_API_PASSWORD as rotas respondem 404.
            'apiDocs' => filled(config('docs.api.password')) ? route('docs.api.index') : null,
        ];

        foreach (self::OPTIONAL_PAGES as $key => $name) {
            $routes[$key] = Route::has($name) ? route($name) : null;
        }

        return $routes;
    }

    /**
     * @return array{sales: string, support: string}
     */
    public static function contact(): array
    {
        return [
            'sales'   => (string) config('mail.contact_address'),
            'support' => (string) config('mail.support_address'),
        ];
    }
}
