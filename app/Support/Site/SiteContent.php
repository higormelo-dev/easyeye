<?php

declare(strict_types=1);

namespace App\Support\Site;

final class SiteContent
{
    public static function translations(): array
    {
        $content = trans('site');

        // Filtrar no servidor também impede que rascunhos apareçam nos props ou no HTML do Inertia.
        if (! config('site.social_proof_enabled', false)) {
            $content['hero']['trust']                    = '';
            $content['hero']['trust_initials']           = [];
            $content['metrics']                          = [];
            $content['metrics_context']                  = '';
            $content['metrics_context_label']            = '';
            $content['testimonials']['items']            = [];
            $content['testimonials']['context']          = '';
            $content['contact']['aside']['quote_text']   = '';
            $content['contact']['aside']['quote_author'] = '';
            $content['contact']['trust_nps']             = '';
        }

        if (empty($content['testimonials']['items'])) {
            $content['nav']['testimonials'] = null;
        }

        return $content;
    }
}
