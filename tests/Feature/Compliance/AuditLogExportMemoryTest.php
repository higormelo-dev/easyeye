<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Entity, User};
use Illuminate\Support\Facades\DB;

/**
 * ComplianceController::exportAuditLogs() monta o CSV só com created_at,
 * user.name, event, auditable_type, auditable_id, ip_address, user_agent —
 * nunca old_values/new_values. Um laudo de imagem embutida pode duplicar
 * ~27 MiB em base64 nessas 2 colunas (metadata congelado —
 * ExamManagerReportController::store()); carregar 500 linhas inteiras por
 * chunk, mesmo sem usar essas colunas, esgotava o memory_limit conforme
 * laudos de imagem se acumulam em audit_logs (achado via revisão
 * adversarial Codex).
 */
it('[GAP] export de auditoria nunca seleciona old_values/new_values', function () {
    $entity     = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $user       = User::factory()->create();
    $entityUser = createEntityUser($entity, $user, ClientRule::Admin->value);

    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);

        if (str_contains($sql, 'audit_logs')) {
            $log[] = $sql;
        }
    });

    // streamedContent(): o callback de response()->streamDownload() só
    // executa de verdade quando o corpo é consumido — assertOk() sozinho
    // não chega a rodar a query (mesmo padrão já usado em
    // tests/Feature/Manager/AiUsageControllerTest.php).
    $this->actingAs($user)->withSession(panelSession($entityUser))
        ->get(route('panel.reports.compliance.audit', [
            'date_from'  => now()->subDay()->toDateString(),
            'date_until' => now()->toDateString(),
        ]))
        ->assertOk()
        ->streamedContent();

    expect($log)->not->toBeEmpty();

    foreach ($log as $sql) {
        expect($sql)->not->toContain('old_values')
            ->and($sql)->not->toContain('new_values')
            ->and($sql)->not->toContain('select *');
    }
});
