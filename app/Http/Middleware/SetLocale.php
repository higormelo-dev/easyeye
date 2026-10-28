<?php

namespace App\Http\Middleware;

use App\Models\Entity;
use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{App, Auth};
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Idiomas suportados pelo sistema.
     */
    public const SUPPORTED_LOCALES = [
        'pt_BR' => [
            'name'   => 'Português (Brasil)',
            'native' => 'Português',
            'flag'   => '🇧🇷',
        ],
        'en' => [
            'name'   => 'English',
            'native' => 'English',
            'flag'   => '🇺🇸',
        ],
    ];

    /**
     * Handle an incoming request.
     *
     * @param Closure(Request): (Response) $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolveLocale();

        // Verifica se o locale é suportado
        if (! array_key_exists($locale, self::SUPPORTED_LOCALES)) {
            $locale = config('app.locale');
        }

        App::setLocale($locale);

        // Carbon::isoFormat depende do locale dele (não compartilha com app()).
        // Mapeia 'en' → 'en' e 'pt_BR' → 'pt_BR' (formato suportado nativamente).
        Carbon::setLocale($locale);

        return $next($request);
    }

    /**
     * Resolve o locale na seguinte ordem de prioridade:
     * 1. Sessão (quando o usuário altera manualmente)
     * 2. Preferência do usuário (salva no banco)
     * 3. Configuração da empresa (entity)
     * 4. Configuração padrão do sistema (config/app.php)
     */
    protected function resolveLocale(): string
    {
        // 1. Verifica se há um locale na sessão (alteração manual)
        if (session()->has('locale')) {
            return session('locale');
        }

        // 2. Se usuário está autenticado, verifica preferências
        if (Auth::check()) {
            $user = Auth::user();

            // Preferência do usuário tem prioridade
            if ($user->locale) {
                return $user->locale;
            }

            // Verifica se há uma entidade selecionada
            $entityId = session('selected_entity_id');

            if ($entityId) {
                $entity = Entity::find($entityId);

                if ($entity && $entity->locale) {
                    return $entity->locale;
                }
            }
        }

        // 4. Retorna o locale padrão do sistema
        return config('app.locale', 'pt_BR');
    }

    /**
     * Retorna os idiomas suportados.
     */
    public static function getSupportedLocales(): array
    {
        return self::SUPPORTED_LOCALES;
    }
}
