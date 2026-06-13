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
            return response()->json([]);
        }

        $results = Cid10Code::search($q)
            ->limit(15)
            ->get(['id', 'code', 'description', 'category']);

        return response()->json($results);
    }
}
