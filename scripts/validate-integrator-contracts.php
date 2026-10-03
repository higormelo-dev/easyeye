<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

require dirname(__DIR__) . '/vendor/autoload.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$spec     = Yaml::parseFile(dirname(__DIR__) . '/docs/api/integrators-openapi.yaml');
$failures = [];

foreach (Route::getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'api/integrators/')) {
        continue;
    }

    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }

        if (! isset($spec['paths']['/' . $route->uri()][strtolower($method)])) {
            $failures[] = 'Uncatalogued route ' . $method . ' /' . $route->uri();
        }
    }
}
function p2ValidateSchema(mixed $value, array $schema, array $spec, string $path, array &$failures): void
{
    if ($value === null && ($schema['nullable'] ?? false)) {
        return;
    }

    if (isset($schema['$ref'])) {
        $target = $spec;

        foreach (explode('/', substr($schema['$ref'], 2)) as $part) {
            if (! isset($target[$part])) {
                $failures[] = $path . ' unresolved reference';

                return;
            }$target = $target[$part];
        }
        p2ValidateSchema($value, $target, $spec, $path, $failures);

        return;
    }

    if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
        $failures[] = $path . ' invalid enum';
    }
    $type  = $schema['type'] ?? null;
    $valid = match($type) {
        'string' => is_string($value),'integer' => is_int($value),'number' => is_int($value) || is_float($value),'boolean' => is_bool($value),'array' => is_array($value) && array_is_list($value),'object' => is_array($value),default => true,
    };

    if (! $valid) {
        $failures[] = $path . ' invalid ' . $type;

        return;
    }

    if ($type === 'object') {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $failures[] = $path . '.' . $key . ' missing';
            }
        }

        if (($schema['additionalProperties'] ?? true) === false) {
            foreach (array_keys($value) as $key) {
                if (! isset($schema['properties'][$key])) {
                    $failures[] = $path . '.' . $key . ' extra';
                }
            }
        }

        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (array_key_exists($key, $value)) {
                p2ValidateSchema($value[$key], $property, $spec, $path . '.' . $key, $failures);
            }
        }
    }

    if ($type === 'array') {
        if (count($value) > ($schema['maxItems'] ?? PHP_INT_MAX)) {
            $failures[] = $path . ' exceeds maxItems';
        }

        foreach ($value as $i => $item) {
            p2ValidateSchema($item, $schema['items'] ?? [], $spec, $path . '[' . $i . ']', $failures);
        }
    }

    if (is_int($value) && ($value < ($schema['minimum'] ?? PHP_INT_MIN) || $value > ($schema['maximum'] ?? PHP_INT_MAX))) {
        $failures[] = $path . ' integer bound';
    }

    if (is_string($value) && mb_strlen($value) > ($schema['maxLength'] ?? PHP_INT_MAX)) {
        $failures[] = $path . ' text bound';
    }
}
$artifactDir = $argv[1] ?? getenv('P2_ARTIFACT_DIR') ?: dirname(__DIR__) . '/storage/framework/testing/p2';

foreach (['snapshot-contract.json' => 'Snapshot', 'update-v2-fixture.json' => 'UpdateMetadata'] as $name => $schemaName) {
    $file = $artifactDir . '/' . $name;

    if (! is_file($file)) {
        $failures[] = 'Missing generated HTTP fixture ' . $name;

        continue;
    }
    $value = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);

    if ($schemaName === 'UpdateMetadata') {
        $value = $value['metadata'];
    }
    p2ValidateSchema($value, $spec['components']['schemas'][$schemaName], $spec, $schemaName, $failures);
}

if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");

    exit(1);
}
echo "Integrator route inventory and generated HTTP fixtures match OpenAPI.\n";
