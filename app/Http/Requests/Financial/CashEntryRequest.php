<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use App\Enums\{CashEntryReferenceType, FinancialEntryStatus, FinancialEntryType, PaymentMethod};
use App\Models\FinancialCashEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\{Rule, Validator};

class CashEntryRequest extends FormRequest
{
    /** Maior valor que cabe em financial_cash_entries.amount (decimal 12,2). */
    private const MAX_AMOUNT = '9999999999.99';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        // A categoria precisa ser do MESMO tipo do lançamento: antes uma despesa
        // podia ser salva com categoria de receita e os relatórios por categoria
        // saíam errados. Tipo inválido (ou não-string) vira '' e nenhuma
        // categoria casa — o erro de `type` aparece junto.
        $type = is_string($this->input('type')) ? (string) $this->input('type') : '';

        return [
            'entry_date'  => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'type'        => ['required', Rule::enum(FinancialEntryType::class)],
            'status'      => ['nullable', Rule::enum(FinancialEntryStatus::class)],
            'amount'      => ['required', 'numeric', 'min:0', 'max:' . self::MAX_AMOUNT],
            'category_id' => [
                'nullable',
                'uuid',
                Rule::exists('financial_categories', 'id')->where(function ($query) use ($entityId, $type) {
                    $query->where(function ($q) use ($entityId) {
                        $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                    })->whereNull('deleted_at')
                        ->where('type', $type);
                }),
            ],
            'covenant_id' => [
                'nullable',
                'uuid',
                Rule::exists('covenants', 'id')->where(function ($query) use ($entityId) {
                    $query->where(function ($q) use ($entityId) {
                        $q->where('entity_id', $entityId)->orWhereNull('entity_id');
                    })->whereNull('deleted_at');
                }),
            ],
            'payment_method' => ['nullable', Rule::enum(PaymentMethod::class)],
            // Vínculo de sistema (App\Enums\CashEntryReferenceType): só o backend
            // define (recebimento de agendamento, guia, compra). Antes era
            // string livre + uuid sem escopo de clínica, gravados crus.
            // Permitia marcar agendamento (até de outra clínica) como pago,
            // bloquear o recebimento legítimo e desvincular um recebimento.
            // `missing` recusa a chave mesmo com null, então nunca chega ao
            // validated() — um update não toca a referência gravada pelo sistema.
            'reference_type' => ['missing'],
            'reference_id'   => ['missing'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'active'         => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Recebimento da agenda com pagamento dividido (dinheiro + cartão): valor e
     * forma vêm do breakdown gravado na chegada (amount_cash/credit/debit) e
     * não mudam pelo Fluxo de Caixa — senão o total deixa de bater com as
     * parcelas. Os demais campos (status, descrição, categoria...) seguem
     * editáveis. A tela mostra os dois campos só leitura.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $entry = $this->route('entry');

            if (! $entry instanceof FinancialCashEntry || ! self::isScheduleSplit($entry)) {
                return;
            }

            $message = __('financial_cash_flow.schedule_split_locked');

            if ($this->has('amount') && is_numeric($this->input('amount'))
                && self::cents($this->input('amount')) !== self::cents($entry->amount)) {
                $validator->errors()->add('amount', $message);
            }

            if ($this->has('payment_method')
                && (string) $this->input('payment_method') !== (string) $entry->payment_method?->value) {
                $validator->errors()->add('payment_method', $message);
            }
        }];
    }

    /** Lançamento da agenda com pagamento dividido entre dinheiro e cartão. */
    public static function isScheduleSplit(FinancialCashEntry $entry): bool
    {
        return $entry->reference_type === CashEntryReferenceType::Schedule->value
            && ($entry->amount_cash !== null || $entry->amount_credit !== null || $entry->amount_debit !== null);
    }

    private static function cents(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category_id.exists'     => __('financial_cash_flow.category_type_mismatch'),
            'reference_type.missing' => __('financial_cash_flow.reference_managed_by_system'),
            'reference_id.missing'   => __('financial_cash_flow.reference_managed_by_system'),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return (array) trans('financial_cash_flow.attributes');
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['active'] as $field) {
            if ($this->has($field)) {
                $merge[$field] = $this->normalizeBoolean($this->input($field));
            }
        }

        foreach (['type', 'status', 'payment_method'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $merge[$field] = mb_strtolower(trim($this->input($field)));
            }
        }

        if (! empty($merge)) {
            $this->merge($merge);
        }
    }

    private function normalizeBoolean(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && in_array($value, [0, 1], true)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return match (mb_strtolower(trim($value))) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no' => false,
            default => $value,
        };
    }
}
