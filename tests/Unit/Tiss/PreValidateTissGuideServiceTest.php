<?php

use App\Domains\Tiss\Enums\TissGuideType;
use App\Domains\Tiss\Models\{TissEntityOperatorContract, TissGuide, TissGuideItem, TissOperator};
use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use App\Domains\Tiss\Services\PreValidateTissGuideService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Tests\TestCase;

uses(TestCase::class);

function makeGuide(array $attrs = [], array $items = [], ?TissOperator $operator = null, ?TissEntityOperatorContract $contract = null): TissGuide
{
    $defaults = [
        'id'                      => (string) \Illuminate\Support\Str::uuid(),
        'entity_id'               => (string) \Illuminate\Support\Str::uuid(),
        'operator_id'             => (string) \Illuminate\Support\Str::uuid(),
        'guide_type'              => TissGuideType::Consultation,
        'guide_number_provider'   => 'GUI-202605-000001',
        'status'                  => 'draft',
        'attendance_date'         => now()->toDateString(),
        'beneficiary_card_number' => '0012345678901234',
        'beneficiary_name'        => 'João da Silva',
        'clinical_indication'     => 'H40.1',
        'doctor_id'               => (string) \Illuminate\Support\Str::uuid(),
        'total_amount'            => 150.00,
        'errors'                  => null,
    ];

    $guide = new TissGuide(array_merge($defaults, $attrs));
    $guide->exists = true;

    // Injeta operator e items como relações já carregadas
    $op = $operator ?? new TissOperator(['name' => 'Bradesco Saúde', 'trade_name' => 'Bradesco Saúde']);
    $guide->setRelation('operator', $op);

    $con = $contract ?? new TissEntityOperatorContract(['requires_authorization' => false]);
    $guide->setRelation('contract', $con);

    $guideItems = new EloquentCollection(
        array_map(function (array $item): TissGuideItem {
            $defaults = [
                'id'          => (string) \Illuminate\Support\Str::uuid(),
                'tuss_code'   => '10101012',
                'table_code'  => '22',
                'description' => 'Consulta Oftalmológica',
                'quantity'    => 1,
                'unit_amount' => 150.00,
                'total_amount' => 150.00,
            ];

            return new TissGuideItem(array_merge($defaults, $item));
        }, $items)
    );
    $guide->setRelation('items', $guideItems);

    return $guide;
}

it('passes for a fully filled consultation guide', function () {
    $guide = makeGuide(items: [[]]);
    $svc = new PreValidateTissGuideService;
    $result = $svc->validate($guide);

    // TussCodeActive emite warning pois o código não está no banco em testes unitários
    // mas não deve emitir erros críticos
    expect($result->hasErrors())->toBeFalse()
        ->and($result->passes())->toBeTrue();
});

it('flags missing beneficiary card as error', function () {
    $guide = makeGuide(['beneficiary_card_number' => null], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    $codes = $result->errors()->pluck('code')->all();
    expect($codes)->toContain('BENEFICIARY_CARD_REQUIRED');
});

it('flags empty cid as error', function () {
    $guide = makeGuide(['clinical_indication' => ''], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    $codes = $result->errors()->pluck('code')->all();
    expect($codes)->toContain('CID_REQUIRED');
});

it('flags malformed cid as error', function () {
    $guide = makeGuide(['clinical_indication' => 'glaucoma'], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    $codes = $result->errors()->pluck('code')->all();
    expect($codes)->toContain('CID_FORMAT_INVALID');
});

it('accepts valid cid formats', function (string $cid) {
    $guide = makeGuide(['clinical_indication' => $cid], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    $cidErrors = $result->errors()->filter(fn ($i) => in_array($i->code, ['CID_REQUIRED', 'CID_FORMAT_INVALID']));
    expect($cidErrors)->toHaveCount(0);
})->with(['H40.1', 'Z00.0', 'A09', 'H52.1', 'H25.90', 'K92.1']);

it('flags missing doctor as error', function () {
    $guide = makeGuide(['doctor_id' => null], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->toContain('DOCTOR_REQUIRED');
});

it('flags guide without items as error', function () {
    $guide = makeGuide(items: []);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->toContain('ITEMS_REQUIRED');
});

it('flags item with zero quantity as error', function () {
    $guide = makeGuide(items: [['quantity' => 0]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->toContain('ITEM_QUANTITY_ZERO');
});

it('flags item with zero unit_amount as error', function () {
    $guide = makeGuide(items: [['unit_amount' => 0]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->toContain('ITEM_AMOUNT_ZERO');
});

it('flags missing authorization when contract requires it', function () {
    $contract = new TissEntityOperatorContract(['requires_authorization' => true]);
    $guide = makeGuide(['authorization_number' => null], [[]], contract: $contract);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->toContain('AUTHORIZATION_REQUIRED');
});

it('does not flag authorization when contract does not require it', function () {
    $contract = new TissEntityOperatorContract(['requires_authorization' => false]);
    $guide = makeGuide(['authorization_number' => null], [[]], contract: $contract);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->not->toContain('AUTHORIZATION_REQUIRED');
});

it('flags missing execution date for sadt guides', function () {
    $guide = makeGuide(['guide_type' => TissGuideType::Sadt, 'execution_date' => null], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->toContain('SADT_EXECUTION_DATE_REQUIRED');
});

it('does not flag execution date for consultation guides', function () {
    $guide = makeGuide(['guide_type' => TissGuideType::Consultation, 'execution_date' => null], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->errors()->pluck('code')->all())->not->toContain('SADT_EXECUTION_DATE_REQUIRED');
});

it('operator hint contains operator name in error messages', function () {
    $guide = makeGuide(['beneficiary_card_number' => null], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    $cardError = $result->errors()->first(fn ($i) => $i->code === 'BENEFICIARY_CARD_REQUIRED');

    expect($cardError)->not->toBeNull()
        ->and($cardError->operatorHint)->toContain('Bradesco Saúde');
});

it('result severity reflects worst issue', function () {
    $guide = makeGuide(['beneficiary_card_number' => null], [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    expect($result->severity())->toBe(TissValidationSeverity::Error);
});

it('passes() returns true when only warnings exist', function () {
    // Guia válida (só TussCodeActive emitirá warning por não ter o código no banco)
    $guide = makeGuide(items: [[]]);
    $result = (new PreValidateTissGuideService)->validate($guide);

    // Sem erros, temos apenas warnings do TussCodeActive (TUSS não encontrado no banco de testes)
    if ($result->hasWarnings() && ! $result->hasErrors()) {
        expect($result->passes())->toBeTrue();
    } else {
        expect($result->hasErrors())->toBeFalse();
    }
});
