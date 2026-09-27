<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TermVersion;
use App\Support\Site\SiteLinks;
use Inertia\{Inertia, Response};

/**
 * Páginas públicas de Política de Privacidade e Termos de Uso.
 *
 * Mostram a versão vigente dos documentos oficiais (term_versions, os mesmos
 * tipos de TermsService::REQUIRED_TYPES que os usuários aceitam no painel):
 * nenhum texto jurídico mora no código. Sem versão publicada, a página diz
 * isso e oferece o e-mail de suporte. O consentimento do formulário de
 * contato do site aponta para /privacidade.
 */
class SiteLegalController extends Controller
{
    public function privacy(): Response
    {
        return $this->render('privacy', 'privacy_policy');
    }

    public function terms(): Response
    {
        return $this->render('terms', 'terms_of_service');
    }

    private function render(string $kind, string $type): Response
    {
        $document = TermVersion::currentFor($type);

        return Inertia::render('Site/Legal', [
            'kind'     => $kind,
            'document' => $document ? [
                'version'       => (string) $document->version,
                'effectiveFrom' => $document->effective_from?->locale(app()->getLocale())->isoFormat('LL'),
                // Texto puro: o front escapa e só preserva parágrafos e quebras de linha.
                'content' => (string) $document->content,
            ] : null,
            'appName' => config('app.name', 'EasyEye'),
            't'       => [
                'nav'    => trans('site.nav'),
                'footer' => trans('site.footer'),
                'legal'  => trans('site.legal'),
            ],
            'routes'       => SiteLinks::routes(),
            'contact'      => SiteLinks::contact(),
            'canonicalUrl' => url()->current(),
        ]);
    }
}
