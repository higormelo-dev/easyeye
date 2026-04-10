<?php

namespace App\Http\Controllers;

use App\Enums\ExamCategory;
use App\Models\{Doctor, ExamType, PatientExam};
use Illuminate\Http\Request;
use Illuminate\View\View;

class EyeImagesController extends Controller
{
    /**
     * Display the Eye Images module.
     */
    public function index(Request $request): View
    {
        $entityId = session('selected_entity_id');

        $meta = [
            'title'            => __('dashboard.module_eye_images'),
            'breadcrumb_title' => false,
            'action'           => __('dashboard.module_eye_images'),
            'breadcrumbs'      => [
                [
                    'label'  => __('actions.sidemenu.dashboard'),
                    'url'    => route('panel.dashboard'),
                    'active' => false,
                ],
                [
                    'label'  => __('dashboard.module_eye_images'),
                    'url'    => 'javascript:void(0);',
                    'active' => true,
                ],
            ],
        ];

        $doctors    = Doctor::with('person')->whereHas('entityUser', fn ($q) => $q->where('entity_id', $entityId))->get();
        $examTypes  = ExamType::orderBy('name')->get();
        $categories = ExamCategory::options();

        return view('system.eye_images.index', compact('meta', 'doctors', 'examTypes', 'categories'));
    }
}
