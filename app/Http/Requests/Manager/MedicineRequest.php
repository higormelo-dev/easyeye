<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Item do catálogo global de medicamentos (manager → Medicamentos).
 * Itens importados da CMED só aceitam a posologia sugerida — o resto é
 * sobrescrito na próxima importação (ver MedicinesController::update).
 */
class MedicineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // Criação exige nome; na edição cada campo só é validado se vier
        // (toggle de ativo, posologia de item da CMED).
        $presence = $this->isMethod('post') ? [] : ['sometimes'];

        return [
            'name'                     => [...$presence, 'required', 'string', 'max:255'],
            'active_ingredient'        => ['nullable', 'string', 'max:1000'],
            'concentration'            => ['nullable', 'string', 'max:255'],
            'medicine_presentation_id' => [
                'nullable', 'uuid',
                Rule::exists('medicine_presentations', 'id')->whereNull('entity_id')->whereNull('deleted_at'),
            ],
            'dosage'        => ['nullable', 'string', 'max:255'],
            'frequency'     => ['nullable', 'string', 'max:255'],
            'duration'      => ['nullable', 'string', 'max:255'],
            'instructions'  => ['nullable', 'string', 'max:2000'],
            'is_ophthalmic' => ['boolean'],
            'active'        => ['boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name'                     => __('manager_medicines.field_name'),
            'active_ingredient'        => __('manager_medicines.field_active_ingredient'),
            'concentration'            => __('manager_medicines.field_concentration'),
            'medicine_presentation_id' => __('manager_medicines.field_presentation'),
            'dosage'                   => __('manager_medicines.field_dosage'),
            'frequency'                => __('manager_medicines.field_frequency'),
            'duration'                 => __('manager_medicines.field_duration'),
            'instructions'             => __('manager_medicines.field_instructions'),
        ];
    }
}
