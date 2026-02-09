<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\EntityIntegratorResource;
use App\Models\{EntityIntegrator, EntityUserIntegrator};
use Carbon\Carbon;
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class EntityIntegratorsController extends Controller
{
    /**
     * Instance of the standard model.
     */
    protected EntityUserIntegrator $model;

    public function __construct(EntityUserIntegrator $entityUserIntegrator)
    {
        $this->model = $entityUserIntegrator;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->model->query()
            ->where('email', $request->get('email'))
            ->first();

        if (! $user || ! Hash::check($request->get('password'), $user->password)) {

            return $this->invalidResponse('auth.failed');
        }

        if (! $user->active) {
            return $this->invalidResponse('auth.inactive');
        }

        $integrator = EntityIntegrator::query()
            ->where('entity_user_integrator_id', $user->id)
            ->where('code', $request->get('code'))
            ->where('active', true)
            ->first();

        if (! $integrator) {
            return $this->invalidResponse('auth.integrator_invalid');
        }

        $token = $user->createToken(
            'integrator-token',
            ['integrator_id:' . $integrator->id],
            Carbon::now()->addDay(7)
        );

        return response()->json(
            (new EntityIntegratorResource($integrator, $token)),
            HttpResponse::HTTP_OK
        );
    }

    public function checkToken(Request $request): JsonResponse
    {
        $token = $request->get('token');

        if (! $token) {
            return $this->invalidResponse('auth.token_not_provided', HttpResponse::HTTP_BAD_REQUEST);
        }

        $accessToken = PersonalAccessToken::findToken($token);

        if (! $accessToken) {
            return $this->invalidResponse('auth.token_invalid');
        }

        if ($accessToken->expires_at && $accessToken->expires_at->isPast()) {
            return $this->invalidResponse('auth.token_expired');
        }

        $integratorId = $this->extractIntegratorId($accessToken->abilities);

        if (! $integratorId) {
            return $this->invalidResponse('auth.token_invalid');
        }

        $integrator = EntityIntegrator::query()
            ->with('user.entity')
            ->where('id', $integratorId)
            ->where('active', true)
            ->first();

        if (! $integrator) {
            return $this->invalidResponse('auth.integrator_inactive');
        }

        if (! $integrator->user->active) {
            return $this->invalidResponse('auth.user_integrator_inactive');
        }

        if (! ($integrator->user->entity && $integrator->user->entity->active)) {
            return $this->invalidResponse('auth.entity_inactive');
        }

        // Verifica se o token vai expirar em 1 dia útil e renova automaticamente
        $renewed   = false;
        $expiresAt = $accessToken->expires_at;

        if ($expiresAt && $this->willExpireInOneBusinessDay($expiresAt)) {
            $accessToken->expires_at = Carbon::now()->addDays(7);
            $accessToken->save();
            $expiresAt = $accessToken->expires_at;
            $renewed   = true;
        }

        return response()->json([
            'message'    => $renewed ? __('auth.token_renewed') : __('auth.token_valid'),
            'valid'      => true,
            'renewed'    => $renewed,
            'expires_at' => $expiresAt,
        ], HttpResponse::HTTP_OK);
    }

    /**
     * Revogar token (logout)
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        // Remove os atributos definidos no middleware
        $request->attributes->remove('user');
        $request->attributes->remove('integrator');

        return response()->json([
            'message' => 'Token revoked successfully.',
        ], HttpResponse::HTTP_OK);
    }

    private function extractIntegratorId(array $abilities): ?string
    {
        foreach ($abilities as $key => $value) {
            $ability = is_int($key) ? $value : $key;

            if (str_starts_with($ability, 'integrator_id:')) {
                $parts = explode(':', $ability, 2);

                return $parts[1] ?? null;
            }
        }

        return null;
    }

    private function invalidResponse(string $messageKey, int $status = HttpResponse::HTTP_UNAUTHORIZED): JsonResponse
    {
        return response()->json(
            ['message' => __($messageKey), 'valid' => false],
            $status
        );
    }

    /**
     * Verifica se o token vai expirar em 1 dia útil.
     */
    private function willExpireInOneBusinessDay(Carbon $expiresAt): bool
    {
        $now          = Carbon::now();
        $businessDays = $this->countBusinessDays($now, $expiresAt);

        return $businessDays <= 1;
    }

    /**
     * Conta dias úteis entre duas datas.
     */
    private function countBusinessDays(Carbon $start, Carbon $end): int
    {
        $days    = 0;
        $current = $start->copy()->addDay();

        while ($current <= $end) {
            if ($this->isBusinessDay($current)) {
                $days++;
            }
            $current->addDay();
        }

        return $days;
    }

    /**
     * Verifica se é dia útil.
     */
    private function isBusinessDay(Carbon $date): bool
    {
        if ($date->isWeekend()) {
            return false;
        }

        if ($this->isHoliday($date)) {
            return false;
        }

        return true;
    }

    /**
     * Verifica se é feriado nacional.
     */
    private function isHoliday(Carbon $date): bool
    {
        $year     = $date->year;
        $holidays = $this->getHolidays($year);

        return in_array($date->format('Y-m-d'), $holidays);
    }

    /**
     * Retorna os feriados nacionais do ano.
     */
    private function getHolidays(int $year): array
    {
        // Feriados fixos
        $holidays = [
            "{$year}-01-01", // Confraternização Universal
            "{$year}-04-21", // Tiradentes
            "{$year}-05-01", // Dia do Trabalho
            "{$year}-09-07", // Independência do Brasil
            "{$year}-10-12", // Nossa Senhora Aparecida
            "{$year}-11-02", // Finados
            "{$year}-11-15", // Proclamação da República
            "{$year}-11-20", // Consciência Nacional
            "{$year}-12-25", // Natal
        ];

        // Feriados móveis (baseados na Páscoa)
        $easter = $this->getEasterDate($year);

        $holidays[] = $easter->copy()->subDays(48)->format('Y-m-d'); // Segunda de Carnaval
        $holidays[] = $easter->copy()->subDays(47)->format('Y-m-d'); // Terça de Carnaval
        $holidays[] = $easter->copy()->subDays(46)->format('Y-m-d'); // Quarta de Carnaval
        $holidays[] = $easter->copy()->subDays(2)->format('Y-m-d');  // Sexta-feira Santa
        $holidays[] = $easter->format('Y-m-d');                       // Páscoa
        $holidays[] = $easter->copy()->addDays(60)->format('Y-m-d'); // Corpus Christi

        return $holidays;
    }

    /**
     * Calcula a data da Páscoa para o ano.
     */
    private function getEasterDate(int $year): Carbon
    {
        $days = easter_days($year);

        return Carbon::createFromDate($year, 3, 21)->addDays($days);
    }
}
