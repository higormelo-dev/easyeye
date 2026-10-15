<?php

declare(strict_types=1);

namespace App\Services\Surgery;

use App\Enums\Surgery\{ImplantKind, ImplantStatus, ImplantStockSource, SurgeryEye, SurgicalCaseStatus};
use App\Exceptions\ExpiredLotException;
use App\Exceptions\Surgery\SurgeryRuleException;
use App\Models\{Doctor, EntityProduct, StockLot};
use App\Models\Surgery\{SurgicalCase, SurgicalImplant};
use App\Models\Surgery\SurgicalCaseIolPlan;
use App\Services\Stock\StockService;
use App\Support\Surgery\{CanonicalHash, LocalTime};
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\{Auth, DB};
use Illuminate\Support\Str;

/**
 * Rastreabilidade do implante (RDC 556/2021, ADR 0003 D5/D6).
 *
 * Registrar grava, na MESMA transação: a baixa de estoque (quando a unidade é
 * da clínica) e o implante CONGELADO (produto, fabricante, ANVISA, modelo,
 * potência, lote, série e validade copiados — o cadastro vivo pode mudar
 * depois). Qualquer recusa desfaz tudo: nunca fica baixa sem implante nem
 * implante sem baixa.
 *
 * Ordem de locks (ADR §5): linha do caso → implantes do caso (id crescente) →
 * lock de série (advisory, por escopo+série) → estoque (produto → lote, ordem
 * do StockService). Duas cirurgias com a mesma série simultânea se serializam
 * no lock de série: a segunda vê a primeira e é recusada; o índice único
 * parcial no banco é a rede de proteção.
 *
 * Implante de FORA do estoque (consignado do hospital/fornecedor) também é
 * registrado — com os mesmos dados congelados e a mesma unicidade de série —,
 * só que sem baixa de saldo.
 */
class SurgeryImplantService
{
    /** Estados em que o implante pode ser registrado ou anulado. */
    private const OPEN_STATES = [SurgicalCaseStatus::Scheduled, SurgicalCaseStatus::Performed];

    public function __construct(
        private readonly SurgicalCaseService $cases,
        private readonly StockService $stock,
        private readonly SurgeryParticipants $participants,
    ) {
    }

    /**
     * @param array<string, mixed> $input source (clinic_stock|external), eye, entity_product_id, stock_lot_id,
     *                                    serial_number, power, quantity, anvisa_registration, model_reference,
     *                                    implanted_at e, para source=external: kind, product_name, manufacturer,
     *                                    lot_number, expiry_date
     */
    public function register(SurgicalCase $case, array $input, Doctor $registrar): SurgicalImplant
    {
        return DB::transaction(function () use ($case, $input, $registrar): SurgicalImplant {
            $locked = $this->cases->lock((string) $case->getKey());

            $this->assertOpen($locked);
            $this->participants->assertMember($locked, $registrar);

            // Filhos do caso na ordem do ADR (id crescente).
            SurgicalImplant::query()->where('surgical_case_id', $locked->getKey())->orderBy('id')->lockForUpdate()->get();

            $eye         = $this->eye($locked, (string) ($input['eye'] ?? ''));
            $implantedAt = $this->implantedAt($locked, $input['implanted_at'] ?? null);
            $source      = ImplantStockSource::tryFrom((string) ($input['source'] ?? 'clinic_stock')) ?? ImplantStockSource::ClinicStock;

            $frozen = $source === ImplantStockSource::ClinicStock
                ? $this->fromClinicStock($locked, $input, $implantedAt)
                : $this->fromExternal($input, $implantedAt);

            $serial = $frozen['serial_number'];

            $deviationReason = $this->planDeviationReason($locked, $eye, $frozen, $input);

            if ($serial !== null) {
                $this->lockSerial((string) $locked->entity_id, $frozen['serial_scope'], $serial);
                $this->assertSerialFree((string) $locked->entity_id, $frozen['serial_scope'], $serial);
            }

            $id       = (string) Str::uuid();
            $movement = null;

            if ($source === ImplantStockSource::ClinicStock) {
                $movement = $this->stock->consumptionOut(
                    product: $frozen['product'],
                    quantity: (float) $frozen['quantity'],
                    note: __('surgery_operation.stock.implant_note', ['case' => $locked->code]),
                    occurredAt: $implantedAt,
                    referenceType: SurgicalImplant::class,
                    referenceId: $id,
                    lot: $frozen['lot'],
                );
            }

            $implant = new SurgicalImplant([
                'entity_id'               => $locked->entity_id,
                'surgical_case_id'        => $locked->getKey(),
                'patient_id'              => $locked->patient_id,
                'kind'                    => $frozen['kind'],
                'stock_source'            => $source,
                'entity_product_id'       => $frozen['product']?->getKey(),
                'stock_lot_id'            => $frozen['lot']?->getKey(),
                'eye'                     => $eye,
                'product_name'            => $frozen['product_name'],
                'manufacturer'            => $frozen['manufacturer'],
                'anvisa_registration'     => $frozen['anvisa_registration'],
                'model_reference'         => $frozen['model_reference'],
                'power'                   => $frozen['power'],
                'lot_number'              => $frozen['lot_number'],
                'serial_number'           => $serial,
                'expiry_date'             => $frozen['expiry_date'],
                'quantity'                => $frozen['quantity'],
                'serial_scope'            => $frozen['serial_scope'],
                'stock_movement_id'       => $movement?->getKey(),
                'status'                  => ImplantStatus::Active,
                'registered_by'           => Auth::id(),
                'registered_by_doctor_id' => $registrar->getKey(),
                'implanted_at'            => $implantedAt,
                'content_hash'            => '',
                'plan_deviation_reason'   => $deviationReason,
            ]);
            $implant->id           = $id;
            $implant->content_hash = CanonicalHash::of([
                'v'       => CanonicalHash::VERSION,
                'case'    => (string) $locked->getKey(),
                'patient' => (string) $locked->patient_id,
                'content' => $implant->frozenContent(),
            ]);

            try {
                // Savepoint: a violação do índice único aborta só esta tentativa.
                DB::transaction(fn () => $implant->save());
            } catch (UniqueConstraintViolationException) {
                throw SurgeryRuleException::invalid('surgery_operation.errors.implant_serial_duplicate', ['serial' => (string) $serial]);
            }

            return $implant;
        });
    }

    /**
     * Anula o implante (nunca apaga). `$returnToStock`: lente lacrada volta ao
     * estoque (estorno do consumo); sem ele a unidade é PERDA — estorna o
     * consumo e lança a perda, para a baixa aparecer como perda/quebra no
     * ledger e não como uso em paciente. Unidade de fora do estoque não mexe
     * em saldo. Só com o caso ainda sem alta.
     */
    public function void(SurgicalImplant $implant, string $reason, bool $returnToStock, Doctor $by): SurgicalImplant
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.void_reason_required');
        }

        return DB::transaction(function () use ($implant, $reason, $returnToStock, $by): SurgicalImplant {
            // Caso primeiro, depois o implante (ordem do ADR).
            $locked = $this->cases->lock((string) $implant->surgical_case_id);

            // Anular vale em qualquer estado, menos depois da alta: sobra de implante em caso
            // que já saiu do Agendado (dado antigo) não pode ficar sem como estornar.
            if ($locked->status === SurgicalCaseStatus::Discharged) {
                throw SurgeryRuleException::state('surgery_operation.errors.implant_case_state', ['status' => $locked->status->label()]);
            }

            $this->participants->assertMember($locked, $by);

            /** @var SurgicalImplant $current */
            $current = SurgicalImplant::query()
                ->where('surgical_case_id', $locked->getKey())
                ->whereKey($implant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $current->isActive()) {
                throw SurgeryRuleException::state('surgery_operation.errors.implant_already_voided');
            }

            $attributes = [
                'status'      => ImplantStatus::Voided,
                'voided_at'   => now(),
                'void_reason' => mb_substr($reason, 0, 500),
                'voided_by'   => Auth::id(),
            ];

            if ($current->stock_source === ImplantStockSource::ClinicStock && $current->stock_movement_id !== null) {
                $consumption = $current->movement()->withoutGlobalScopes()->firstOrFail();

                $reversal = $this->stock->reverseConsumption(
                    $consumption,
                    __('surgery_operation.stock.void_note', ['case' => $locked->code]),
                    null,
                    SurgicalImplant::class,
                    (string) $current->getKey(),
                    (string) $locked->entity_id,
                );

                $attributes['reversal_movement_id'] = $reversal->getKey();
                $attributes['void_stock_action']    = $returnToStock ? 'returned' : 'lost';

                if (! $returnToStock && $current->entity_product_id !== null) {
                    $product = EntityProduct::query()->withoutGlobalScopes()->findOrFail($current->entity_product_id);
                    $lot     = $current->stock_lot_id !== null ? StockLot::query()->withoutGlobalScopes()->find($current->stock_lot_id) : null;

                    $loss = $this->stock->loss(
                        $product,
                        (float) $current->quantity,
                        __('surgery_operation.stock.loss_note', ['case' => $locked->code]),
                        null,
                        $lot,
                    );

                    $attributes['loss_movement_id'] = $loss->getKey();
                }
            }

            $current->forceFill($attributes)->save();

            return $current;
        });
    }

    // ── Regras de cada origem ───────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function fromClinicStock(SurgicalCase $locked, array $input, DateTimeInterface $implantedAt): array
    {
        $product = EntityProduct::query()
            ->where('entity_id', $locked->entity_id)
            ->where('active', true)
            ->find($input['entity_product_id'] ?? null);

        if ($product === null) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_product_not_found');
        }

        $kind        = $product->iolLens()->exists() ? ImplantKind::Iol : ImplantKind::Other;
        $lotRequired = $kind === ImplantKind::Iol || $product->requires_lot || $product->is_opm;
        $serialReq   = $kind === ImplantKind::Iol || $product->requires_serial;

        $lot = null;

        if (filled($input['stock_lot_id'] ?? null)) {
            $lot = StockLot::query()
                ->where('entity_id', $locked->entity_id)
                ->where('entity_product_id', $product->getKey())
                ->where('active', true)
                ->find($input['stock_lot_id']);

            if ($lot === null) {
                throw SurgeryRuleException::invalid('surgery_operation.errors.implant_lot_not_found');
            }
        } elseif ($lotRequired) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_lot_required');
        }

        if ($lot !== null && $lotRequired && $lot->expiry_date === null) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_lot_expiry_required', ['lot' => $lot->lot_number]);
        }

        if ($lot !== null && $lot->expiry_date !== null && $lot->expiry_date->toDateString() < $implantedAt->format('Y-m-d')) {
            throw new ExpiredLotException($product, $lot);
        }

        $serial = $this->serial($input['serial_number'] ?? null);

        if ($serialReq && $serial === null) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_serial_required', ['product' => $product->name]);
        }

        $quantity = ($kind === ImplantKind::Iol || $serial !== null) ? 1 : max(1, min(99, (int) ($input['quantity'] ?? 1)));
        $power    = $this->power($input['power'] ?? null, required: $kind === ImplantKind::Iol);

        $anvisa = $this->anvisa($input['anvisa_registration'] ?? null)
            ?? $this->knownAnvisa($locked, $product);

        if ($anvisa === null && ($kind === ImplantKind::Iol || $product->is_opm)) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_anvisa_required');
        }

        return [
            'product'             => $product,
            'lot'                 => $lot,
            'kind'                => $kind,
            'product_name'        => $product->name,
            'manufacturer'        => $product->manufacturer ?: ($this->text($input['manufacturer'] ?? null, 120)),
            'anvisa_registration' => $anvisa,
            'model_reference'     => $this->text($input['model_reference'] ?? null, 80) ?? $this->text($product->sku, 80),
            'power'               => $power,
            'lot_number'          => $lot?->lot_number,
            'serial_number'       => $serial,
            'expiry_date'         => $lot?->expiry_date,
            'quantity'            => $quantity,
            'serial_scope'        => (string) $product->getKey(),
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function fromExternal(array $input, DateTimeInterface $implantedAt): array
    {
        $kind = ImplantKind::tryFrom((string) ($input['kind'] ?? '')) ?? ImplantKind::Other;
        $name = $this->text($input['product_name'] ?? null, 255);
        $mfr  = $this->text($input['manufacturer'] ?? null, 120);
        $ref  = $this->text($input['model_reference'] ?? null, 80);
        $lot  = $this->text($input['lot_number'] ?? null, 60);

        if ($name === null || $mfr === null || $ref === null || $lot === null) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_external_incomplete');
        }

        $expiry = filled($input['expiry_date'] ?? null) ? Carbon::parse((string) $input['expiry_date']) : null;

        if ($expiry === null) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_external_expiry_required');
        }

        if ($expiry->toDateString() < $implantedAt->format('Y-m-d')) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_external_expired', ['date' => $expiry->isoFormat('L')]);
        }

        $serial = $this->serial($input['serial_number'] ?? null);

        if ($kind === ImplantKind::Iol && $serial === null) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_serial_required', ['product' => $name]);
        }

        $anvisa = $this->anvisa($input['anvisa_registration'] ?? null);

        if ($anvisa === null) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_anvisa_required');
        }

        return [
            'product'             => null,
            'lot'                 => null,
            'kind'                => $kind,
            'product_name'        => $name,
            'manufacturer'        => $mfr,
            'anvisa_registration' => $anvisa,
            'model_reference'     => $ref,
            'power'               => $this->power($input['power'] ?? null, required: $kind === ImplantKind::Iol),
            'lot_number'          => $lot,
            'serial_number'       => $serial,
            'expiry_date'         => $expiry,
            'quantity'            => ($kind === ImplantKind::Iol || $serial !== null) ? 1 : max(1, min(99, (int) ($input['quantity'] ?? 1))),
            'serial_scope'        => 'ext:' . mb_strtolower($mfr) . '|' . mb_strtolower($ref),
        ];
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    /**
     * A LIO registrada confere com a planejada do olho (potência e, se o plano
     * aponta um produto, o produto)? Divergência só passa com a justificativa do
     * cirurgião (mínimo de 10 caracteres), gravada no implante.
     *
     * @param array<string, mixed> $frozen
     * @param array<string, mixed> $input
     */
    private function planDeviationReason(SurgicalCase $locked, SurgeryEye $eye, array $frozen, array $input): ?string
    {
        if ($frozen['kind'] !== ImplantKind::Iol) {
            return null;
        }

        $plan = $locked->iolPlans()->where('eye', $eye->value)->first();

        if ($plan === null || ! self::deviates($frozen['power'], $frozen['product']?->getKey(), $plan)) {
            return null;
        }

        $reason = trim((string) ($input['plan_deviation_reason'] ?? ''));

        if (mb_strlen($reason) < 10) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_plan_deviation', [
                'planned'    => number_format((float) $plan->power, 2, (string) __('surgery_operation.decimal_separator'), ''),
                'registered' => number_format((float) $frozen['power'], 2, (string) __('surgery_operation.decimal_separator'), ''),
                'model'      => $plan->model_label,
                'min'        => 10,
            ]);
        }

        return mb_substr($reason, 0, 2000);
    }

    /** Potência diferente da planejada, ou produto diferente do que o plano aponta. */
    public static function deviates(float|string|null $power, ?string $productId, SurgicalCaseIolPlan $plan): bool
    {
        if ($power === null || abs((float) $power - (float) $plan->power) > 0.001) {
            return true;
        }

        return $plan->entity_product_id !== null && (string) $plan->entity_product_id !== (string) $productId;
    }

    private function assertOpen(SurgicalCase $locked): void
    {
        if (! in_array($locked->status, self::OPEN_STATES, true)) {
            throw SurgeryRuleException::state('surgery_operation.errors.implant_case_state', ['status' => $locked->status->label()]);
        }
    }

    private function eye(SurgicalCase $locked, string $value): SurgeryEye
    {
        $eye = SurgeryEye::tryFrom($value);

        if ($eye === null || $eye === SurgeryEye::Both) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_eye_required');
        }

        if ($locked->eye !== SurgeryEye::Both && $locked->eye !== $eye) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_eye_mismatch', ['eye' => $locked->eye->label()]);
        }

        return $eye;
    }

    private function implantedAt(SurgicalCase $locked, mixed $value): Carbon
    {
        $at = LocalTime::parse(filled($value) ? (string) $value : null) ?? now();

        if ($at->greaterThan(now()->addMinutes(5))) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_date_future');
        }

        if ($locked->scheduled_start !== null && $at->lessThan($locked->scheduled_start->copy()->startOfDay())) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_date_before_surgery');
        }

        return $at;
    }

    private function serial(mixed $value): ?string
    {
        $serial = mb_strtoupper(trim((string) $value));

        return $serial === '' ? null : mb_substr($serial, 0, 60);
    }

    private function anvisa(mixed $value): ?string
    {
        $anvisa = preg_replace('/\s+/', '', trim((string) $value)) ?? '';

        return $anvisa === '' ? null : mb_substr($anvisa, 0, 15);
    }

    private function text(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function power(mixed $value, bool $required): ?float
    {
        if (! is_numeric($value)) {
            if ($required) {
                throw SurgeryRuleException::invalid('surgery_operation.errors.implant_power_required');
            }

            return null;
        }

        $power = round((float) $value, 2);

        if ($power < -10 || $power > 60) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_power_range');
        }

        return $power;
    }

    /** Registro ANVISA já conhecido para o produto: pedido de OPME do caso ou implante anterior. */
    private function knownAnvisa(SurgicalCase $locked, EntityProduct $product): ?string
    {
        $fromMaterial = $locked->materials()
            ->where('entity_product_id', $product->getKey())
            ->whereNotNull('anvisa_registration')
            ->value('anvisa_registration');

        if (filled($fromMaterial)) {
            return $this->anvisa($fromMaterial);
        }

        return $this->anvisa(
            SurgicalImplant::query()
                ->where('entity_id', $locked->entity_id)
                ->where('entity_product_id', $product->getKey())
                ->whereNotNull('anvisa_registration')
                ->orderByDesc('implanted_at')
                ->value('anvisa_registration'),
        );
    }

    private function lockSerial(string $entityId, string $scope, string $serial): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["surgical-implant-serial|{$entityId}|{$scope}|{$serial}"]);
        }
    }

    private function assertSerialFree(string $entityId, string $scope, string $serial): void
    {
        $taken = SurgicalImplant::query()
            ->withoutGlobalScopes()
            ->where('entity_id', $entityId)
            ->where('serial_scope', $scope)
            ->where('serial_number', $serial)
            ->where('status', ImplantStatus::Active->value)
            ->exists();

        if ($taken) {
            throw SurgeryRuleException::invalid('surgery_operation.errors.implant_serial_duplicate', ['serial' => $serial]);
        }
    }
}
