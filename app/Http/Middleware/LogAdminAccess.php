<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\{Auth, DB, Log};
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogAdminAccess
{
    // Rotas que geram muito ruído e não precisam ser auditadas individualmente
    private const SKIP_ROUTES = [
        'manager.plans.cards',
        'manager.entities.cards',
        'manager.subscriptions.cards',
        'manager.report-settings.cards',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Só registra após a resposta bem-sucedida (não em erros 4xx/5xx)
        if ($response->isSuccessful() || $response->isRedirection()) {
            $routeName = $request->route()?->getName() ?? 'unknown';

            if (! in_array($routeName, self::SKIP_ROUTES, true)) {
                $this->log($request, $routeName);
            }
        }

        return $response;
    }

    private function log(Request $request, string $routeName): void
    {
        try {
            DB::table('audit_logs')->insert([
                'id'             => \Illuminate\Support\Str::orderedUuid()->toString(),
                'entity_id'      => session('selected_entity_id'),
                'user_id'        => Auth::id(),
                'auditable_type' => 'manager_panel',
                'auditable_id'   => \Illuminate\Support\Str::uuid()->toString(),
                'event'          => 'access',
                'old_values'     => null,
                'new_values'     => json_encode([
                    'route'  => $routeName,
                    'url'    => $request->fullUrl(),
                    'method' => $request->method(),
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LogAdminAccess: falha ao registrar acesso admin.', [
                'route' => $routeName,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
