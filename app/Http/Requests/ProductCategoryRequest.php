<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\ProductCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('product_categories', 'name')
                    ->ignore($this->getIgnoredCategoryId(), 'id')
                    ->where(fn ($query) => $query
                        ->where('entity_id', session('selected_entity_id'))
                        ->whereNull('deleted_at')),
            ],
        ];

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['active'] = ['required', 'boolean'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => trans('validation.custom.generic.required'),
        ];
    }

    private function getIgnoredCategoryId(): ?string
    {
        if (! $this->isMethod('PUT') && ! $this->isMethod('PATCH')) {
            return null;
        }

        $id = $this->route('productcategory');

        $category = ProductCategory::query()
            ->where('entity_id', session('selected_entity_id'))
            ->where('id', $id)
            ->first();

        return $category->id ?? null;
    }
}
