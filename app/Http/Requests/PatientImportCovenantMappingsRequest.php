<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\PatientImport;
use App\Services\Covenants\PatientImportCovenantMappingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT do de-para de convênios da importação de pacientes: só a forma do corpo. As regras que dependem do banco (nome no
 * arquivo, convênio/plano ativos e da clínica, duplicidade pela chave normalizada, `settings.manage` na criação) ficam
 * no PatientImportCovenantMappingService, tudo-ou-nada, com erros em `mappings.{i}.{campo}`.
 *
 * O tenant do import é checado em authorize(), ANTES das regras: import de outra clínica (ou inexistente) é 404 com qualquer
 * corpo — sem 422 que revelaria a existência do UUID.
 */
class PatientImportCovenantMappingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // O binding da rota pode vir antes do escopo de tenant: a clínica da sessão é a fonte da verdade.
        $import = $this->route('patientImport');

        abort_unless($import instanceof PatientImport && (string) $import->entity_id === (string) session('selected_entity_id'), 404);

        return true; // rota: permission:patients.manage,admin,financial,doctor,secretary; criar convênio: settings.manage no serviço
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'mappings'                    => ['required', 'array', 'min:1', 'max:1000'],
            'mappings.*'                  => ['array'],
            'mappings.*.source_name'      => ['required', 'string', 'max:255'],
            'mappings.*.action'           => ['required', 'string', Rule::in(PatientImportCovenantMappingService::ACTIONS)],
            'mappings.*.covenant_id'      => ['nullable', 'string', 'max:64'],
            'mappings.*.covenant_plan_id' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mappings.*.action.in' => __('imports.patients.covenant_mapping.invalid_action'),
        ];
    }
}
