<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TermVersion;
use App\Support\Site\{SiteContent, SiteLinks};
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\{Inertia, Response};

/**
 * Páginas públicas de Política de Privacidade e Termos de Uso.
 *
 * Mostram a versão vigente dos documentos oficiais (term_versions, os mesmos
 * tipos de TermsService::REQUIRED_TYPES que os usuários aceitam no painel):
 * o conteúdo exibido é sempre o registro publicado. Sem versão publicada, a página diz
 * isso e oferece o e-mail de suporte. O consentimento do formulário de
 * contato do site aponta para /privacidade.
 *
 * Idiomas: o texto oficial é o em português. Em outro idioma, a página mostra
 * a tradução de cortesia da mesma versão (term_versions.translations), com
 * aviso de que o português prevalece; sem tradução, mostra o original e avisa.
 * `?original=1` mostra o original mantendo o resto da página no idioma atual.
 */
class SiteLegalController extends Controller
{
    public function privacy(Request $request): Response
    {
        return $this->render($request, 'privacy', 'privacy_policy');
    }

    public function terms(Request $request): Response
    {
        return $this->render($request, 'terms', 'terms_of_service');
    }

    private function render(Request $request, string $kind, string $type): Response
    {
        $document  = TermVersion::currentFor($type);
        $locale    = app()->getLocale();
        $isForeign = $locale !== TermVersion::OFFICIAL_LOCALE;
        $text      = $document?->contentFor($request->boolean('original') ? TermVersion::OFFICIAL_LOCALE : $locale);

        return Inertia::render('Site/Legal', [
            'kind'     => $kind,
            'document' => $document ? [
                'version'       => (string) $document->version,
                'effectiveFrom' => $document->effective_from?->locale($locale)->isoFormat('LL'),
                // Texto puro: o front escapa e só preserva parágrafos e quebras de linha.
                'content' => $text['content'],
                // Idioma do texto exibido (atributo lang do documento).
                'contentLang'   => str_replace('_', '-', $text['locale']),
                'isTranslation' => $text['is_translation'],
                // Visitante em outro idioma lendo o original em português.
                'isOriginal'     => $isForeign && ! $text['is_translation'],
                'hasTranslation' => $isForeign && $document->contentFor($locale)['is_translation'],
                'originalUrl'    => url()->current() . '?original=1',
                'translationUrl' => url()->current(),
            ] : null,
            'appName'      => config('app.name', 'EasyEye'),
            't'            => Arr::only(SiteContent::translations(), ['nav', 'footer', 'legal']),
            'routes'       => SiteLinks::routes(),
            'contact'      => SiteLinks::contact(),
            'canonicalUrl' => url()->current(),
        ]);
    }
}
