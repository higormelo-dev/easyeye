<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ClientRule;
use App\Models\UserPreference;
use Illuminate\Http\Request;

/**
 * Tour guiado do painel da clínica (driver.js — ver
 * resources/js/composables/usePanelTour.js).
 *
 * Um tour por PERFIL na clínica (`panel:<perfil>`): o menu muda com o perfil,
 * então quem é médico numa clínica e admin em outra vê os dois tours. Estado
 * por usuário em user_preferences.data.tours (acompanha o usuário entre
 * dispositivos):
 *
 *   {"panel:doctor": {"version": 1, "status": "completed", "at": "2026-09-29T16:40:00-03:00"}}
 *
 * Subir VERSION faz o tour reaparecer uma vez para todos (conteúdo mudou).
 * Impersonação (suporte vendo como o usuário) não dispara nem grava nada.
 */
final class PanelTour
{
    // 2: passos do Dashboard (tela inicial) antes do menu.
    public const VERSION = 2;

    public const STATUSES = ['completed', 'dismissed'];

    /**
     * Ids aceitos no bag de preferências: um por perfil de cliente (o mapa
     * nunca passa de ClientRule::cases()).
     *
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_map(fn (ClientRule $rule) => 'panel:' . $rule->value, ClientRule::cases());
    }

    /**
     * Props do tour para o painel de clínica; null fora dele (painel SaaS,
     * portais, páginas públicas).
     *
     * `page`: passos da tela atual (tour.pages[rota]) — só os dela vão para o
     * front, não os de todas as telas.
     *
     * @return array{id: string, version: int, seen: bool, auto: bool, page: array<string, array{title: string, description: string}>|null, t: array<string, mixed>}|null
     */
    public static function props(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null || ! $request->routeIs('panel.*') || ! session('selected_entity_is_client')) {
            return null;
        }

        $rule = ClientRule::tryFrom((string) session('selected_entity_user_rule'));

        if ($rule === null) {
            return null;
        }

        $id    = 'panel:' . $rule->value;
        $state = ((array) UserPreference::valueFor($user, 'tours', []))[$id] ?? null;
        $texts = (array) trans('tour');
        $pages = (array) ($texts['pages'] ?? []);
        unset($texts['pages']);

        return [
            'id'      => $id,
            'version' => self::VERSION,
            'seen'    => is_array($state) && (int) ($state['version'] ?? 0) >= self::VERSION,
            // Impersonação: o tour pode ser aberto pelo botão, mas não abre
            // sozinho nem grava estado na conta do usuário.
            'auto' => ! session()->has('impersonating'),
            'page' => $pages[(string) $request->route()?->getName()] ?? null,
            't'    => $texts,
        ];
    }
}
