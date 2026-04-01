<?php

namespace Tests\Feature;

use App\Enums\ClientRule;
use App\Models\{Entity, EntityUser, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Testa que o middleware entity.role bloqueia corretamente
 * cada role nas rotas do painel.
 *
 * Matriz de acesso:
 * ┌────────────┬───────────┬───────────┬──────────────┬──────────────┬─────────────────┐
 * │ Rota       │ admin     │ financial │ doctor       │ secretary    │ user            │
 * ├────────────┼───────────┼───────────┼──────────────┼──────────────┼─────────────────┤
 * │ patients   │ ✓         │ ✓         │ ✓            │ ✓            │ 403             │
 * │ doctors    │ ✓         │ ✓         │ ✓            │ ✓            │ 403             │
 * │ schedules  │ ✓         │ 403       │ ✓            │ ✓            │ 403             │
 * │ reports    │ ✓         │ ✓         │ 403          │ 403          │ 403             │
 * │ compliance │ ✓         │ 403       │ 403          │ 403          │ 403             │
 * │ users      │ ✓         │ 403       │ 403          │ 403          │ 403             │
 * └────────────┴───────────┴───────────┴──────────────┴──────────────┴─────────────────┘
 */
class AclTest extends TestCase
{
    use RefreshDatabase;

    private Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = Entity::factory()->create(['is_client' => true]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Autentica o user e seta a sessão do painel multi-tenant.
     */
    private function actingAsRole(string $rule): static
    {
        $user       = User::factory()->create();
        $entityUser = EntityUser::create([
            'entity_id' => $this->entity->id,
            'user_id'   => $user->id,
            'rule'      => $rule,
            'active'    => true,
            'is_owner'  => false,
            'joined_at' => now(),
        ]);

        return $this->actingAs($user)->withSession([
            'selected_entity_id'        => $this->entity->id,
            'selected_entity_user_id'   => $entityUser->id,
            'selected_entity_user_rule' => $rule,
            'selected_entity_is_client' => true,
        ]);
    }

    // ── /panel/patients (admin, financial, doctor, secretary) ─────────────

    public function test_user_nao_pode_acessar_patients(): void
    {
        $this->actingAsRole(ClientRule::User->value)
            ->get('/panel/patients')
            ->assertForbidden();
    }

    public function test_secretary_pode_acessar_patients(): void
    {
        $this->actingAsRole(ClientRule::Secretary->value)
            ->get('/panel/patients')
            ->assertOk();
    }

    public function test_doctor_pode_acessar_patients(): void
    {
        $this->actingAsRole(ClientRule::Doctor->value)
            ->get('/panel/patients')
            ->assertOk();
    }

    public function test_financial_pode_acessar_patients(): void
    {
        $this->actingAsRole(ClientRule::Financial->value)
            ->get('/panel/patients')
            ->assertOk();
    }

    // ── /panel/schedules (admin, doctor, secretary) ───────────────────────

    public function test_financial_nao_pode_acessar_schedules(): void
    {
        $this->actingAsRole(ClientRule::Financial->value)
            ->get('/panel/schedules')
            ->assertForbidden();
    }

    public function test_user_nao_pode_acessar_schedules(): void
    {
        $this->actingAsRole(ClientRule::User->value)
            ->get('/panel/schedules')
            ->assertForbidden();
    }

    public function test_doctor_pode_acessar_schedules(): void
    {
        // Usa waiting-list (mesmo grupo de middleware, sem DataTable)
        $this->actingAsRole(ClientRule::Doctor->value)
            ->get('/panel/waiting-list')
            ->assertOk();
    }

    public function test_secretary_pode_acessar_schedules(): void
    {
        $this->actingAsRole(ClientRule::Secretary->value)
            ->get('/panel/waiting-list')
            ->assertOk();
    }

    // ── /panel/reports (admin, financial) ────────────────────────────────

    public function test_doctor_nao_pode_acessar_reports(): void
    {
        $this->actingAsRole(ClientRule::Doctor->value)
            ->get('/panel/reports')
            ->assertForbidden();
    }

    public function test_secretary_nao_pode_acessar_reports(): void
    {
        $this->actingAsRole(ClientRule::Secretary->value)
            ->get('/panel/reports')
            ->assertForbidden();
    }

    public function test_user_nao_pode_acessar_reports(): void
    {
        $this->actingAsRole(ClientRule::User->value)
            ->get('/panel/reports')
            ->assertForbidden();
    }

    public function test_financial_pode_acessar_reports(): void
    {
        $this->actingAsRole(ClientRule::Financial->value)
            ->get('/panel/reports')
            ->assertOk();
    }

    public function test_admin_pode_acessar_reports(): void
    {
        $this->actingAsRole(ClientRule::Admin->value)
            ->get('/panel/reports')
            ->assertOk();
    }

    // ── /panel/reports/compliance (admin only) ────────────────────────────

    public function test_financial_nao_pode_acessar_compliance(): void
    {
        $this->actingAsRole(ClientRule::Financial->value)
            ->get('/panel/reports/compliance')
            ->assertForbidden();
    }

    public function test_doctor_nao_pode_acessar_compliance(): void
    {
        $this->actingAsRole(ClientRule::Doctor->value)
            ->get('/panel/reports/compliance')
            ->assertForbidden();
    }

    public function test_admin_pode_acessar_compliance(): void
    {
        $this->actingAsRole(ClientRule::Admin->value)
            ->get('/panel/reports/compliance')
            ->assertOk();
    }

    // ── /panel/accesscontrol/users (admin only) ───────────────────────────

    public function test_financial_nao_pode_acessar_users(): void
    {
        $this->actingAsRole(ClientRule::Financial->value)
            ->get('/panel/accesscontrol/users')
            ->assertForbidden();
    }

    public function test_doctor_nao_pode_acessar_users(): void
    {
        $this->actingAsRole(ClientRule::Doctor->value)
            ->get('/panel/accesscontrol/users')
            ->assertForbidden();
    }

    public function test_secretary_nao_pode_acessar_users(): void
    {
        $this->actingAsRole(ClientRule::Secretary->value)
            ->get('/panel/accesscontrol/users')
            ->assertForbidden();
    }

    public function test_admin_pode_acessar_users(): void
    {
        $this->actingAsRole(ClientRule::Admin->value)
            ->get('/panel/accesscontrol/users')
            ->assertOk();
    }

    // ── /panel/setting/* (admin only) ─────────────────────────────────────

    public function test_financial_nao_pode_acessar_settings(): void
    {
        $this->actingAsRole(ClientRule::Financial->value)
            ->get('/panel/setting/covenants')
            ->assertForbidden();
    }

    public function test_doctor_nao_pode_acessar_settings(): void
    {
        $this->actingAsRole(ClientRule::Doctor->value)
            ->get('/panel/setting/covenants')
            ->assertForbidden();
    }

    public function test_admin_pode_acessar_settings(): void
    {
        $this->actingAsRole(ClientRule::Admin->value)
            ->get('/panel/setting/covenants')
            ->assertOk();
    }
}
