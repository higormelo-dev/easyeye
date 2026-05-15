<?php

namespace App\Http\Controllers;

use App\Models\Plan;

class SiteController extends Controller
{
    public function index()
    {
        $plans = Plan::active()
            ->with(['features' => fn ($q) => $q->orderBy('feature')])
            ->orderBy('sort_order')
            ->get();

        return view('site.index', compact('plans'));
    }
}
