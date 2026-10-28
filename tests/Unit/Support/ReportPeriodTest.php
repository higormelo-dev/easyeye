<?php

use App\Support\ReportPeriod;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => Carbon::setTestNow('2026-09-26 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('aceita período válido', function () {
    expect(ReportPeriod::resolve('2026-09-01', '2026-09-15'))->toBe(['2026-09-01', '2026-09-15']);
});

it('cai no mês atual quando a data é inválida, ausente ou array (antes: erro 500)', function (mixed $from, mixed $to) {
    expect(ReportPeriod::resolve($from, $to))->toBe(['2026-09-01', '2026-09-26']);
})->with([
    'texto'                         => ['abc', 'xyz'],
    'nulos'                         => [null, null],
    'array'                         => [['2026-01-01'], ['x']],
    'data inexistente'              => ['2026-02-30', '2026-13-01'],
    'formato BR'                    => ['01/09/2026', '26/09/2026'],
    'ano zero (PostgreSQL rejeita)' => ['0000-01-01', '0000-12-31'],
]);

it('usa os padrões informados pelo chamador', function () {
    expect(ReportPeriod::resolve(null, null, '2026-01-01', '2026-01-31'))->toBe(['2026-01-01', '2026-01-31']);
});

it('troca período invertido', function () {
    expect(ReportPeriod::resolve('2026-09-20', '2026-09-01'))->toBe(['2026-09-01', '2026-09-20']);
});

it('uuidOrNull só aceita UUID', function () {
    expect(ReportPeriod::uuidOrNull('01a0dbcc-3ec5-71d1-89a3-78c02291b878'))->toBe('01a0dbcc-3ec5-71d1-89a3-78c02291b878')
        ->and(ReportPeriod::uuidOrNull('123'))->toBeNull()
        ->and(ReportPeriod::uuidOrNull(['x']))->toBeNull()
        ->and(ReportPeriod::uuidOrNull(null))->toBeNull();
});

it('text só aceita string', function () {
    expect(ReportPeriod::text('  pago '))->toBe('pago')
        ->and(ReportPeriod::text(['x'], 'all'))->toBe('all')
        ->and(ReportPeriod::text(null, 'all'))->toBe('all');
});
