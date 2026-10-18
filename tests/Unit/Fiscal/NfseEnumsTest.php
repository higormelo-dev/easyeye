<?php

use App\Enums\Fiscal\{NfseStandard, NfseStatus};

it('mapeia os status da Focus e ignora os desconhecidos', function () {
    expect(NfseStatus::fromFocus('processando_autorizacao'))->toBe(NfseStatus::Processing)
        ->and(NfseStatus::fromFocus('autorizado'))->toBe(NfseStatus::Authorized)
        ->and(NfseStatus::fromFocus('erro_autorizacao'))->toBe(NfseStatus::AuthorizationError)
        ->and(NfseStatus::fromFocus('cancelado'))->toBe(NfseStatus::Cancelled)
        // O consulta do padrão nacional também devolve `negado` (DPS recusada pelo ambiente nacional).
        ->and(NfseStatus::fromFocus('negado'))->toBe(NfseStatus::AuthorizationError)
        // Estados nossos nunca vêm da Focus; qualquer valor novo da API não pode virar um status inventado.
        ->and(NfseStatus::fromFocus('pending_send'))->toBeNull()
        ->and(NfseStatus::fromFocus('erro_cancelamento'))->toBeNull()
        ->and(NfseStatus::fromFocus('substituida'))->toBeNull()
        ->and(NfseStatus::fromFocus(null))->toBeNull();
});

it('considera "ocupando" só quem está a caminho ou valendo', function () {
    $occupying = array_filter(NfseStatus::cases(), fn (NfseStatus $status) => $status->isOccupying());

    expect(array_values(array_map(fn (NfseStatus $status) => $status->value, $occupying)))->toEqualCanonicalizing([
        'pending_send', 'processando_autorizacao', 'autorizado',
    ])
        ->and(NfseStatus::occupyingValues())->toEqualCanonicalizing(['pending_send', 'processando_autorizacao', 'autorizado']);
});

it('liga o padrão ao endpoint e ao evento do webhook da Focus', function () {
    expect(NfseStandard::Municipal->endpoint())->toBe('nfse')
        ->and(NfseStandard::National->endpoint())->toBe('nfsen')
        ->and(NfseStandard::Municipal->hookEvent())->toBe('nfse')
        ->and(NfseStandard::National->hookEvent())->toBe('nfsen')
        ->and(NfseStandard::from('nacional'))->toBe(NfseStandard::National);
});
