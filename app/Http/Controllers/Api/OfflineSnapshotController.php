<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\{IntegratorPatientResource,IntegratorScheduleResource};
use App\Models\{ClinicResource, Patient, Schedule};
use App\Services\DataAccessLogService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OfflineSnapshotController extends Controller
{
    public function index(Request $request)
    {
        $v = $request->validate(['clinic_resource_id' => ['required', 'uuid'], 'date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from']]);

        if (Carbon::parse($v['date_from'])->diffInDays(Carbon::parse($v['date_to'])) > 6) {
            return response()->json(['code' => 'snapshot_window_invalid'], 422);
        }
        $integrator = $request->attributes->get('integrator');
        $entity     = $integrator->user->entity_id;
        ClinicResource::where('entity_id', $entity)->where('type', 'equipment')->findOrFail($v['clinic_resource_id']);

        return DB::transaction(function () use ($v, $integrator, $entity, $request) {
            // One consistent snapshot across both queries; does not authorize subsequent uploads.
            if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $schedules = Schedule::with(['patient.person', 'resources'])->where('entity_id', $entity)->whereDate('date_time', '>=', $v['date_from'])->whereDate('date_time', '<=', $v['date_to'])->whereHas('resources', fn ($q) => $q->where('clinic_resources.id', $v['clinic_resource_id']))->orderBy('date_time')->orderBy('id')->limit(501)->get();
            $patients  = Patient::with('person')->where('entity_id', $entity)->whereIn('id', $schedules->pluck('patient_id')->filter()->unique())->orderBy('id')->get();

            if ($schedules->count() + $patients->count() > 500) {
                return response()->json(['code' => 'snapshot_limit_exceeded', 'message' => 'Reduza a janela de datas.'], 422);
            }
            $generated = now();
            $body      = ['clinic_timezone' => config('app.timezone'), 'contract_version' => 1, 'snapshot_id' => (string) Str::uuid(), 'entity_id' => $entity, 'integrator_id' => $integrator->id, ...$v, 'generated_at' => $generated->copy()->utc()->format('Y-m-d\TH:i:s\Z'), 'expires_at' => $generated->copy()->addDay()->utc()->format('Y-m-d\TH:i:s\Z'), 'complete' => true, 'truncated' => false, 'patients' => IntegratorPatientResource::collection($patients)->resolve($request), 'schedules' => IntegratorScheduleResource::collection($schedules)->resolve($request)];
            $json      = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            if (strlen($json) > 1048576) {
                return response()->json(['code' => 'snapshot_limit_exceeded', 'message' => 'Reduza a janela de datas.'], 422);
            }
            app(DataAccessLogService::class)->aggregate('offline_snapshot', $patients->count() + $schedules->count(), $v);

            return response($json, 200, ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store']);
        });
    }
}
