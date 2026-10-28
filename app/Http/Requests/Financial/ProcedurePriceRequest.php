<?php

declare(strict_types=1);

namespace App\Http\Requests\Financial;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule, Validator};

/**
 * Validação do salvamento da tabela de preço por procedimento × convênio.
 *
 * A tela envia SÓ as linhas alteradas, com semântica explícita por linha:
 * `price` informado = grava (upsert); `price` null = remove aquele preço. A
 * chave `price` é obrigatória — linha sem ela é erro, nunca remoção — e o que
 * não vem no lote não muda (ProcedurePriceService::syncForCovenant).
 */
class ProcedurePriceRequest extends FormRequest
{
    /**
     * Maior valor que cabe em procedure_prices.price (decimal 14,2): acima disso
     * o PostgreSQL devolvia erro 500. String para não depender da precisão do float.
     */
    public const PRICE_MAX = '999999999999.99';

    /**
     * Teto de linhas por salvamento. Um reajuste ou cópia na grade inteira de
     * uma clínica cabe com folga; acima disso o lote é recusado sem validar as
     * linhas (payload gigante não vira milhares de regras nem consultas).
     */
    public const MAX_ITEMS = 2000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $entityId = (string) session('selected_entity_id');

        $rules = [
            'covenant_id' => [
                'required',
                'uuid',
                Rule::exists('covenants', 'id')->where(fn ($q) => $q
                    ->where(fn ($sub) => $sub->where('entity_id', $entityId)->orWhereNull('entity_id'))
                    ->whereNull('deleted_at')),
            ],
            'items' => ['present', 'array', 'max:' . self::MAX_ITEMS],
        ];

        if ($this->exceedsItemLimit()) {
            return $rules;
        }

        return $rules + [
            // Existência (da clínica ou global, não excluído) numa consulta só
            // para o lote inteiro: after() — antes era um exists por linha.
            'items.*.procedure_id' => ['required', 'uuid', 'distinct'],
            'items.*.price'        => ['present', 'nullable', 'numeric', 'min:0', 'max:' . self::PRICE_MAX],
            // O valor enviado só vale para convênio com operadora TISS: o
            // ProcedurePriceService grava false nos demais (ex.: Particular).
            'items.*.charging' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateProcedures($validator)];
    }

    /**
     * Nomes legíveis nas mensagens por linha ("O campo preço…" em vez de
     * "O campo items.0.price…"); a tela liga o erro à linha pela chave.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'covenant_id'          => __('financial_procedure_prices.covenant_attribute'),
            'items.*.procedure_id' => __('financial_procedure_prices.procedure_attribute'),
            'items.*.price'        => __('financial_procedure_prices.price_attribute'),
            'items.*.charging'     => __('financial_procedure_prices.charging_attribute'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.max'                     => __('financial_procedure_prices.items_max', ['max' => self::MAX_ITEMS]),
            'items.*.procedure_id.distinct' => __('financial_procedure_prices.procedure_duplicate'),
            'items.*.price.present'         => __('financial_procedure_prices.price_missing'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items) || $this->exceedsItemLimit()) {
            return;
        }

        foreach ($items as $i => $item) {
            // Item que não é objeto (payload adulterado): deixa a validação
            // (items.*.procedure_id required) responder 422 em vez de TypeError 500.
            if (! is_array($item)) {
                continue;
            }

            if (array_key_exists('price', $item)) {
                $items[$i]['price'] = $this->normalizeMoney($item['price']);
            }

            if (array_key_exists('charging', $item)) {
                $items[$i]['charging'] = filter_var($item['charging'], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $this->merge(['items' => $items]);
    }

    /**
     * Procedimentos do lote existem e são da clínica ou globais (não excluídos)?
     * UMA consulta whereIn para todas as linhas; o erro vai para a linha
     * (items.N.procedure_id). Linhas que já falharam em required/uuid/distinct
     * ficam de fora.
     */
    private function validateProcedures(Validator $validator): void
    {
        $items = $this->input('items');

        if (! is_array($items) || $this->exceedsItemLimit()) {
            return;
        }

        $candidates = [];

        foreach ($items as $index => $item) {
            $procedureId = is_array($item) ? ($item['procedure_id'] ?? null) : null;

            if (is_string($procedureId) && Str::isUuid($procedureId) && ! $validator->errors()->has("items.{$index}.procedure_id")) {
                $candidates[$index] = strtolower($procedureId);
            }
        }

        if ($candidates === []) {
            return;
        }

        $entityId = (string) session('selected_entity_id');

        $known = DB::table('procedures')
            ->whereIn('id', array_values(array_unique($candidates)))
            ->where(fn ($q) => $q->where('entity_id', $entityId)->orWhereNull('entity_id'))
            ->whereNull('deleted_at')
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [strtolower((string) $id) => true])
            ->all();

        foreach ($candidates as $index => $procedureId) {
            if (! isset($known[$procedureId])) {
                $validator->errors()->add("items.{$index}.procedure_id", __('financial_procedure_prices.procedure_invalid'));
            }
        }
    }

    private function exceedsItemLimit(): bool
    {
        $items = $this->input('items');

        return is_array($items) && count($items) > self::MAX_ITEMS;
    }

    private function normalizeMoney(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        return str_replace(',', '.', str_replace('.', '', $value));
    }
}
