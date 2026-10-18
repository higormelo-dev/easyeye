<?php

use App\Enums\ClientRule;
use App\Models\{Entity, EntityUser, User};
use App\Support\Fiscal\NfseTomadorSnapshot;

require_once __DIR__ . '/Support/NfseFixtures.php';

/** Vínculo da pessoa com a clínica: perfil e dono definem se ela é contato de cobrança. */
function nfseLinkUser(Entity $clinic, array $user = [], array $link = []): User
{
    $person = User::factory()->create(array_merge(['email_verified_at' => now()], $user));

    EntityUser::query()->create(array_merge([
        'entity_id' => $clinic->id, 'user_id' => $person->id, 'rule' => ClientRule::Admin->value, 'active' => true, 'is_owner' => false,
    ], $link));

    return $person;
}

it('congela nome, documento, e-mail, telefone e endereço completo da clínica', function () {
    $clinic = nfseClinic(['national_registration' => '11444777000161']);

    expect(NfseTomadorSnapshot::from($clinic))->toBe([
        'name'          => 'CLINICA OFTALMO TESTE',
        'document'      => '11444777000161',
        'document_type' => 'cnpj',
        'email'         => 'financeiro@clinica.test',
        'phone'         => '11999998888',
        'address'       => [
            'logradouro'       => 'AVENIDA PAULISTA',
            'numero'           => '1000',
            'complemento'      => 'SALA 12',
            'bairro'           => 'BELA VISTA',
            'uf'               => 'SP',
            'cep'              => '01310100',
            'codigo_municipio' => '3550308',
        ],
    ]);
});

it('reconhece CPF pelos 11 dígitos e mantém o CNPJ alfanumérico em maiúsculas', function () {
    expect(NfseTomadorSnapshot::from(nfseClinic(['national_registration' => '529.982.247-25'])))->toMatchArray(['document' => '52998224725', 'document_type' => 'cpf'])
        ->and(NfseTomadorSnapshot::from(nfseClinic(['national_registration' => '12.abc.345/01de-35'])))->toMatchArray(['document' => '12ABC34501DE35', 'document_type' => 'cnpj']);
});

it('limita a razão social ao tamanho do padrão municipal (115)', function () {
    expect(mb_strlen(NfseTomadorSnapshot::from(nfseClinic(['name' => str_repeat('Ã', 150)]))['name']))->toBe(115);
});

it('usa o celular e cai para o fixo; tira o DDI e descarta o que não é telefone brasileiro', function () {
    expect(NfseTomadorSnapshot::from(nfseClinic(['cellphone' => '5511988887777', 'telephone' => '1133334444']))['phone'])->toBe('11988887777')
        ->and(NfseTomadorSnapshot::from(nfseClinic(['cellphone' => null, 'telephone' => '1133334444']))['phone'])->toBe('1133334444')
        ->and(NfseTomadorSnapshot::from(nfseClinic(['cellphone' => '9999', 'telephone' => '12345']))['phone'] ?? null)->toBeNull();
});

describe('e-mail', function () {
    it('usa o e-mail da clínica', function () {
        $clinic = nfseClinic(['email' => 'contato@clinica.test']);
        nfseLinkUser($clinic, ['email' => 'outra@clinica.test']);

        expect(NfseTomadorSnapshot::from($clinic)['email'])->toBe('contato@clinica.test');
    });

    it('sem e-mail da clínica, usa o primeiro contato de cobrança com e-mail verificado', function () {
        $clinic = nfseClinic(['email' => null]);
        nfseLinkUser($clinic, ['email' => 'nao-verificado@clinica.test', 'email_verified_at' => null]);
        nfseLinkUser($clinic, ['email' => 'medico@clinica.test'], ['rule' => ClientRule::Doctor->value]);
        nfseLinkUser($clinic, ['email' => 'inativo@clinica.test'], ['active' => false]);
        $billing = nfseLinkUser($clinic, ['email' => 'financeiro-real@clinica.test'], ['rule' => ClientRule::Financial->value]);

        expect(NfseTomadorSnapshot::from($clinic)['email'])->toBe($billing->email);
    });

    it('o dono da clínica também é contato de cobrança', function () {
        $clinic = nfseClinic(['email' => '']);
        $owner  = nfseLinkUser($clinic, [], ['rule' => ClientRule::Doctor->value, 'is_owner' => true]);

        expect(NfseTomadorSnapshot::from($clinic)['email'])->toBe($owner->email);
    });

    it('não usa contato de outra clínica e omite o campo quando não há nenhum', function () {
        nfseLinkUser(nfseClinic(), ['email' => 'alheio@outra.test']);

        expect(NfseTomadorSnapshot::from(nfseClinic(['email' => null])))->not->toHaveKey('email');
    });

    it('limita o e-mail a 80 caracteres', function () {
        expect(mb_strlen(NfseTomadorSnapshot::from(nfseClinic(['email' => str_repeat('a', 90) . '@x.test']))['email']))->toBe(80);
    });
});

describe('endereço', function () {
    it('só entra quando está completo (meio endereço não vai)', function (array $override, array $missing) {
        $clinic = nfseClinic($override);

        expect(NfseTomadorSnapshot::from($clinic))->not->toHaveKey('address')
            ->and(NfseTomadorSnapshot::missingAddressFields($clinic))->toBe($missing);
    })->with([
        'sem IBGE'       => [['ibge_code' => null], ['codigo_municipio']],
        'IBGE curto'     => [['ibge_code' => '35503'], ['codigo_municipio']],
        'sem logradouro' => [['address' => ''], ['logradouro']],
        'sem bairro'     => [['district' => null], ['bairro']],
        'CEP inválido'   => [['zipcode' => '0131'], ['cep']],
        'UF inválida'    => [['state' => 'XX'], ['uf']],
        'vários'         => [['address' => '', 'zipcode' => '', 'ibge_code' => null], ['logradouro', 'cep', 'codigo_municipio']],
    ]);

    it('endereço completo não lista nada faltando', function () {
        expect(NfseTomadorSnapshot::missingAddressFields(nfseClinic()))->toBe([]);
    });

    it('cadastro sem número vira S/N, sem complemento omite o campo', function () {
        $address = NfseTomadorSnapshot::from(nfseClinic(['number' => null, 'complement' => null]))['address'];

        expect($address['numero'])->toBe('S/N')
            ->and($address)->not->toHaveKey('complemento');
    });

    it('corta os campos nos limites da Focus', function () {
        $address = NfseTomadorSnapshot::from(nfseClinic(['address' => str_repeat('L', 200), 'number' => str_repeat('9', 20), 'complement' => str_repeat('C', 100), 'district' => str_repeat('B', 100)]))['address'];

        expect(mb_strlen($address['logradouro']))->toBe(125)
            ->and(mb_strlen($address['numero']))->toBe(10)
            ->and(mb_strlen($address['complemento']))->toBe(60)
            ->and(mb_strlen($address['bairro']))->toBe(60);
    });
});

it('diz se o documento da clínica vale (dígito verificador)', function () {
    expect(NfseTomadorSnapshot::hasValidDocument(nfseClinic(['national_registration' => '11444777000161'])))->toBeTrue()
        ->and(NfseTomadorSnapshot::hasValidDocument(nfseClinic(['national_registration' => '11444777000162'])))->toBeFalse()
        ->and(NfseTomadorSnapshot::hasValidDocument(nfseClinic(['national_registration' => null])))->toBeFalse();
});
