<?php

declare(strict_types=1);

/**
 * `CheckFeature` FALHA ABERTA com chave desconhecida (loga e deixa passar, para um
 * typo não derrubar a rota em produção). Isso significa que `feature:has_patient_recal`
 * numa rota liberaria o recurso a TODOS os planos em silêncio — então toda rota que usa o
 * middleware tem que nomear uma FeatureKey que existe.
 */

use App\Enums\FeatureKey;
use Illuminate\Support\Facades\Route;

it('toda rota com o middleware feature: usa uma FeatureKey que existe', function () {
    $bad  = [];
    $used = 0;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_starts_with($middleware, 'feature:')) {
                continue;
            }

            $used++;
            $key = substr($middleware, strlen('feature:'));

            if (FeatureKey::tryFrom($key) === null) {
                $bad[] = ($route->getName() ?? $route->uri()) . ' → ' . $middleware;
            }
        }
    }

    expect($used)->toBeGreaterThan(0, 'o teste precisa enxergar ao menos uma rota com feature:')
        ->and($bad)->toBe([]);
});
