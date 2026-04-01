<?php

declare(strict_types = 1);

namespace App\Http\Controllers;

use App\Enums\EntityGate;
use App\Models\{AuditLog, DataAccessLog, Entity};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceController extends Controller
{
    public function index()
    {
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));

        $meta = [
            'title'       => 'Compliance & Auditoria',
            'action'      => 'Logs de Auditoria e Acesso',
            'breadcrumbs' => [
                ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
                ['label' => __('actions.sidemenu.reports'), 'url' => route('panel.reports.index'), 'active' => false],
                ['label' => 'Compliance & Auditoria', 'url' => 'javascript:void(0)', 'active' => true],
            ],
        ];

        return view('system.reports.compliance', compact('meta'));
    }

    public function exportAuditLogs(Request $request): StreamedResponse
    {
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));

        $request->validate([
            'date_from'  => ['required', 'date'],
            'date_until' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $entityId  = session('selected_entity_id');
        $dateFrom  = Carbon::parse($request->input('date_from'))->startOfDay();
        $dateUntil = Carbon::parse($request->input('date_until'))->endOfDay();
        $filename  = 'auditoria_' . $dateFrom->format('Ymd') . '_' . $dateUntil->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($entityId, $dateFrom, $dateUntil): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

            fputcsv($output, ['Data/Hora', 'Usuário', 'Evento', 'Modelo', 'ID do Registro', 'IP', 'User-Agent'], ';');

            AuditLog::with('user')
                ->where('entity_id', $entityId)
                ->whereBetween('created_at', [$dateFrom, $dateUntil])
                ->orderBy('created_at')
                ->chunk(500, function ($logs) use ($output): void {
                    foreach ($logs as $log) {
                        fputcsv($output, [
                            $log->created_at?->format('d/m/Y H:i:s'),
                            $log->user?->name ?? 'Sistema',
                            $log->event,
                            class_basename($log->auditable_type),
                            $log->auditable_id,
                            $log->ip_address,
                            $log->user_agent,
                        ], ';');
                    }
                });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function exportDataAccessLogs(Request $request): StreamedResponse
    {
        Gate::authorize(EntityGate::ManageSettings->value, Entity::findOrFail(session('selected_entity_id')));

        $request->validate([
            'date_from'  => ['required', 'date'],
            'date_until' => ['required', 'date', 'after_or_equal:date_from'],
        ]);

        $entityId  = session('selected_entity_id');
        $dateFrom  = Carbon::parse($request->input('date_from'))->startOfDay();
        $dateUntil = Carbon::parse($request->input('date_until'))->endOfDay();
        $filename  = 'acessos_dados_' . $dateFrom->format('Ymd') . '_' . $dateUntil->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($entityId, $dateFrom, $dateUntil): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF"); // BOM UTF-8 para Excel

            fputcsv($output, [
                'Data/Hora', 'Usuário', 'Paciente', 'Tipo de Recurso',
                'ID do Recurso', 'Finalidade', 'Justificativa', 'IP',
            ], ';');

            DataAccessLog::with(['user', 'patient.person'])
                ->where('entity_id', $entityId)
                ->whereBetween('accessed_at', [$dateFrom, $dateUntil])
                ->orderBy('accessed_at')
                ->chunk(500, function ($logs) use ($output): void {
                    foreach ($logs as $log) {
                        fputcsv($output, [
                            $log->accessed_at?->format('d/m/Y H:i:s'),
                            $log->user?->name ?? '—',
                            $log->patient?->person?->full_name ?? '—',
                            class_basename($log->resource_type),
                            $log->resource_id,
                            $log->purpose?->label() ?? '—',
                            $log->justification ?? '—',
                            $log->ip_address,
                        ], ';');
                    }
                });

            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
