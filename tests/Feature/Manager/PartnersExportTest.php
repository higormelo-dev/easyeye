<?php

/**
 * Smoke test dos exports de Parceiros (Manager SaaS).
 *
 * Contexto: PartnersReportExport/PartnersController::exportExcel dependiam de
 * maatwebsite/excel e phpoffice/phpspreadsheet sem os pacotes declarados no
 * Composer nem instalados no vendor — qualquer clique em "Exportar Excel"
 * estourava fatal error (classe inexistente). Este teste garante que a rota
 * responde com o binário correto e evita regressão futura (ex.: upgrade para
 * maatwebsite/excel v4, que quebra a assinatura de FromCollection::collection()
 * usada nas sheets).
 */

use App\Enums\PartnerType;
use App\Models\{Entity, Partner, User};
use Illuminate\Support\Str;

beforeEach(function () {
    $this->saas  = Entity::factory()->create(['is_client' => false, 'active' => true]);
    $this->admin = User::factory()->create();
    createEntityUser($this->saas, $this->admin, 'admin');

    Partner::create([
        'name'            => 'Parceiro Teste',
        'email'           => 'parceiro-export@example.com',
        'type'            => PartnerType::Distributor->value,
        'commission_rate' => 10.00,
        'token'           => Str::random(32),
        'status'          => 'active',
    ]);
});

function partnersExportAdminSession(Entity $saas): array
{
    return [
        'selected_entity_id'        => $saas->id,
        'selected_entity_is_client' => false,
        'selected_entity_user_rule' => 'admin',
    ];
}

test('exporta relatório de parceiros em excel sem fatal error de dependência', function () {
    $response = $this->actingAs($this->admin)
        ->withSession(partnersExportAdminSession($this->saas))
        ->get(route('manager.partners.export.excel'));

    $response->assertOk();
    $response->assertHeader(
        'content-type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
});

test('exporta relatório de parceiros em pdf', function () {
    $response = $this->actingAs($this->admin)
        ->withSession(partnersExportAdminSession($this->saas))
        ->get(route('manager.partners.export.pdf'));

    $response->assertOk();
});
