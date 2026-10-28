<?php

declare(strict_types=1);

/**
 * Processo FILHO do PatientDataExportMemoryTest: roda
 * PatientDataExporter::export() de verdade, numa sessão PostgreSQL própria, e
 * devolve em JSON o pico de memória RESIDENTE (RSS) do processo antes e depois
 * da exportação.
 *
 * Por que RSS e não memory_get_peak_usage(): o PDO_PGSQL (libpq) lê o resultado
 * INTEIRO de uma consulta pra memória nativa do processo no execute(); isso não
 * passa pelo gerenciador de memória do PHP, então memory_get_peak_usage() e
 * memory_limit não enxergam — mas o contêiner enxerga (OOM kill).
 *
 * O pico é o VmHWM de /proc/self/status, zerado (clear_refs = 5) logo antes da
 * exportação: ru_maxrss (e o VmHWM sem zerar) guardam o pico do BOOT da
 * aplicação, que é bem maior que o RSS em regime e esconderia o que a
 * exportação acrescenta. `reset=false` = o kernel não deixou zerar (o teste
 * pula).
 *
 * Uso: php lgpd_export_child.php <patient_id>
 */

use App\Models\Patient;
use App\Services\Lgpd\PatientDataExporter;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../../../vendor/autoload.php';

$app = require __DIR__ . '/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $patientId] = $argv;

// VmRSS (atual) e VmHWM (pico desde o último reset), em KiB.
$memory = static function (): array {
    $status = (string) @file_get_contents('/proc/self/status');

    preg_match('/VmRSS:\s+(\d+) kB/', $status, $rss);
    preg_match('/VmHWM:\s+(\d+) kB/', $status, $hwm);

    return ['rss' => (int) ($rss[1] ?? 0), 'hwm' => (int) ($hwm[1] ?? 0)];
};

try {
    $patient = Patient::query()->withoutGlobalScopes()->findOrFail($patientId);

    gc_collect_cycles();

    $reset  = @file_put_contents('/proc/self/clear_refs', '5') !== false;
    $before = $memory();

    $export = app(PatientDataExporter::class)->export($patient);

    fwrite(STDOUT, json_encode([
        'reset'          => $reset,
        'before_rss_kb'  => $before['rss'],
        'peak_kb'        => $memory()['hwm'],
        'php_peak_kb'    => (int) (memory_get_peak_usage() / 1024),
        'documentations' => count($export['medical_records'][0]['documentations'] ?? []),
    ]));
} catch (Throwable $e) {
    fwrite(STDERR, $e::class . ': ' . $e->getMessage() . "\n");

    exit(1);
}
