<?php

declare(strict_types=1);

use App\Enums\ClientRule;
use App\Models\{Doctor, Entity, People, User};
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Listagem de médicos igualada à de pacientes: coluna Telefone (formatada,
 * com WhatsApp), ordenação estável, filtros normalizados, textos via `t` e
 * cards com os mesmos dados da tabela — antes o endpoint `cards` não devolvia
 * cor, especialidade, status nem link de horários (card mostrava "—" sempre).
 */
beforeEach(function () {
    $this->entity      = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->otherEntity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    $this->staff           = User::factory()->create();
    $this->staffEntityUser = createEntityUser($this->entity, $this->staff, ClientRule::Admin->value);
});

function listingDoctor(Entity $entity, array $user = [], array $person = [], array $doctor = []): Doctor
{
    $eu = createEntityUser($entity, User::factory()->create($user), ClientRule::Doctor->value);

    return Doctor::create(array_merge([
        'entity_user_id' => $eu->id,
        'person_id'      => People::factory()->create($person)->id,
        'active'         => true,
    ], $doctor));
}

function doctorsAs(string $route, array $query = [], bool $json = false)
{
    $request = test()->actingAs(test()->staff)->withSession(panelSession(test()->staffEntityUser));

    return $json
        ? $request->getJson(route($route, $query))
        : $request->get(route($route, $query));
}

describe('Médicos — tabela igual à de pacientes', function () {
    it('traz telefone formatado com WhatsApp, textos traduzidos e filtros normalizados', function () {
        listingDoctor($this->entity, ['name' => 'Dra Tel'], ['cellphone' => '61999998888', 'whatsapp' => true]);

        doctorsAs('panel.doctors.index', ['search' => 'Dra Tel', 'sort' => 'drop;table', 'direction' => 'sideways'])
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Panel/Doctors/Index')
                ->where('doctors.data.0.cellphone', '(61) 99999-8888')
                ->where('doctors.data.0.whatsapp', true)
                ->where('filters.sort', 'created_at')
                ->where('filters.direction', 'desc')
                ->has('t.col_phone')
                ->has('t.columns_label'));
    });

    it('ordena por telefone', function () {
        listingDoctor($this->entity, ['name' => 'Ord Fone B'], ['cellphone' => '61988887777']);
        listingDoctor($this->entity, ['name' => 'Ord Fone A'], ['cellphone' => '11911112222']);

        doctorsAs('panel.doctors.index', ['search' => 'Ord Fone', 'sort' => 'cellphone', 'direction' => 'asc'])
            ->assertInertia(fn (Assert $page) => $page
                ->where('doctors.data.0.cellphone', '(11) 91111-2222')
                ->where('doctors.data.1.cellphone', '(61) 98888-7777'));
    });

    it('pagina sem repetir nem pular médicos com o mesmo nome', function () {
        foreach (range(1, 16) as $i) {
            listingDoctor($this->entity, ['name' => 'Mesmo Nome Medico']);
        }

        $ids = collect([1, 2])->flatMap(function (int $page) {
            $data = null;
            doctorsAs('panel.doctors.index', ['search' => 'Mesmo Nome Medico', 'sort' => 'full_name', 'direction' => 'asc', 'page' => $page])
                ->assertInertia(function (Assert $inertia) use (&$data) {
                    $data = $inertia->toArray()['props']['doctors']['data'];
                });

            return collect($data)->pluck('id');
        });

        expect($ids)->toHaveCount(16)
            ->and($ids->unique())->toHaveCount(16);
    });
});

describe('Médicos — cards com os mesmos dados da tabela', function () {
    it('devolve cor, especialidade, status, telefone e link de horários', function () {
        $doctor = listingDoctor(
            $this->entity,
            ['name'      => 'Dr Card'],
            ['cellphone' => '6133334444', 'whatsapp' => false],
            ['record'    => '12345', 'record_specialty' => 'RETINA', 'color' => '#FF0000', 'active' => false],
        );

        doctorsAs('panel.doctors.cards', ['search' => 'Dr Card'], json: true)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $doctor->id)
            ->assertJsonPath('data.0.full_name', 'DR CARD')
            ->assertJsonPath('data.0.record_specialty', 'RETINA')
            ->assertJsonPath('data.0.color', '#FF0000')
            ->assertJsonPath('data.0.active', false)
            ->assertJsonPath('data.0.cellphone', '(61) 3333-4444')
            ->assertJsonPath('data.0.work_schedule_url', route('panel.doctors.work-schedule.index', $doctor->id))
            ->assertJsonPath('data.0.mode', 'full');
    });

    it('busca por CRM, igual à tabela', function () {
        listingDoctor($this->entity, ['name' => 'Dr Crm'], [], ['record' => '998877']);

        doctorsAs('panel.doctors.cards', ['search' => '998877'], json: true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.full_name', 'DR CRM');
    });

    it('não mostra médicos de outra clínica', function () {
        listingDoctor($this->entity, ['name' => 'Isolado Daqui']);
        listingDoctor($this->otherEntity, ['name' => 'Isolado Outra']);

        doctorsAs('panel.doctors.cards', ['search' => 'Isolado'], json: true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.full_name', 'ISOLADO DAQUI');
    });
});

describe('Médicos — ações mantêm a listagem como estava', function () {
    it('ativar/desativar volta para a listagem com busca, ordenação e página', function () {
        $doctor = listingDoctor($this->entity, ['name' => 'Dr Toggle']);
        $from   = route('panel.doctors.index') . '?search=Toggle&sort=full_name&direction=asc&page=2';

        test()->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->from($from)
            ->put(route('panel.doctors.update', $doctor->id), ['active' => false, 'type_method' => 'toggle'])
            ->assertRedirect(route('panel.doctors.index') . '?search=Toggle&sort=full_name&direction=asc&page=2');
    });

    it('[SEGURANÇA] Referer de outro domínio não vira redirect externo e descarta parâmetros estranhos', function () {
        $doctor = listingDoctor($this->entity, ['name' => 'Dr Referer']);

        $response = test()->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->from('https://evil.example/panel/doctors?search=x&next=https://evil.example')
            ->put(route('panel.doctors.update', $doctor->id), ['active' => false, 'type_method' => 'toggle']);

        expect($response->headers->get('Location'))
            ->toStartWith(route('panel.doctors.index'))
            ->not->toContain('evil.example')
            ->not->toContain('next=');
    });

    it('vindo de outra tela, volta para a listagem padrão', function () {
        $doctor = listingDoctor($this->entity, ['name' => 'Dr Outra Tela']);

        test()->actingAs($this->staff)
            ->withSession(panelSession($this->staffEntityUser))
            ->from(route('panel.dashboard'))
            ->put(route('panel.doctors.update', $doctor->id), ['active' => false, 'type_method' => 'toggle'])
            ->assertRedirect(route('panel.doctors.index'));
    });

    it('página além da última (após excluir o último da página) mostra a última válida', function () {
        foreach (range(1, 16) as $i) {
            listingDoctor($this->entity, ['name' => 'Limite Medico']);
        }

        doctorsAs('panel.doctors.index', ['search' => 'Limite Medico', 'page' => 5])
            ->assertInertia(fn (Assert $page) => $page
                ->where('doctors.current_page', 2)
                ->has('doctors.data', 1));

        doctorsAs('panel.doctors.cards', ['search' => 'Limite Medico', 'page' => 9], json: true)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(4, 'data');
    });

    it('busca por telefone, formatado ou só dígitos (igual a pacientes)', function () {
        listingDoctor($this->entity, ['name' => 'Dr Fone Busca'], ['cellphone' => '61977776666']);

        foreach (['(61) 97777-6666', '977776666'] as $term) {
            doctorsAs('panel.doctors.index', ['search' => $term])
                ->assertInertia(fn (Assert $page) => $page
                    ->where('doctors.total', 1)
                    ->where('doctors.data.0.full_name', 'DR FONE BUSCA'));
        }
    });
});
