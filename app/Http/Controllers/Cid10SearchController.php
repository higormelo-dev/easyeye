<?php

namespace App\Http\Controllers;

use App\Models\Cid10Code;
use Illuminate\Http\{JsonResponse, Request};

class Cid10SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = $request->string('q')->trim()->value();

        if (mb_strlen($q, 'UTF-8') < 2) {
            return response()->json($request->string('shape')->value() === 'select' ? ['data' => []] : []);
        }

        $results = Cid10Code::search($q)
            ->limit(15)
            ->get(['id', 'code', 'description', 'category']);

        // shape=select: formato consumido por SearchSelect.vue em modo remoto
        // (Gerenciador de Imagens, filtro de Diagnóstico). Valor = code, não o
        // UUID, porque o filtro casa contra patient_exams.diagnosis_cids[].code.
        if ($request->string('shape')->value() === 'select') {
            return response()->json(['data' => $results->map(fn (Cid10Code $c) => [
                'id'        => $c->code,
                'label'     => $c->code . ' – ' . $c->description,
                'sub_label' => $c->category,
            ])]);
        }

        return response()->json($results);
    }
}
