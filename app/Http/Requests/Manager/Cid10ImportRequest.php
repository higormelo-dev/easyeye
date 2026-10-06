<?php

namespace App\Http\Requests\Manager;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Importação da CID-10 (Manager → CID-10): o CID10CSV.zip do DATASUS ou os
 * CSVs (subcategorias obrigatório; grupos, capítulos e categorias
 * opcionais). Estrutura, codificação e conteúdo do zip são validados em
 * App\Services\Cid10\Cid10ImportFiles.
 */
class Cid10ImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1', 'max:6'],
            // 10 MB por arquivo: o zip oficial tem ~300 KB; o maior CSV ~1,3 MB.
            'files.*' => ['required', 'file', 'mimes:zip,csv,txt', 'max:10240'],
        ];
    }

    public function attributes(): array
    {
        return [
            'files'   => __('manager_cid10.import_files'),
            'files.*' => __('manager_cid10.import_files'),
        ];
    }
}
