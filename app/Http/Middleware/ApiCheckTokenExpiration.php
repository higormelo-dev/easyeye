<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiCheckTokenExpiration
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user() ? $request->user()->currentAccessToken() : null;

        if (! $token) {
            return $next($request);
        }

        $now      = Carbon::now();
        $lastUsed = $token->last_used_at ?? $token->created_at;

        // Calcula dias úteis desde o último uso
        $businessDays = $this->countBusinessDays($lastUsed, $now);

        // Expirou por inatividade (3 dias úteis sem uso)
        if ($businessDays > 3) {
            $token->delete();

            return response()->json([
                'message' => __('auth.token_expired_inactivity'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Expirou pela data máxima (7 dias corridos)
        if ($token->expires_at && $token->expires_at->isPast()) {
            $token->delete();

            return response()->json([
                'message' => __('auth.token_expired'),
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Renova automaticamente se vai expirar em 1 dia útil ou menos
        if ($token->expires_at && $this->willExpireInOneBusinessDay($token->expires_at)) {
            $token->expires_at = Carbon::now()->addDays(7);
            $token->save();
        }

        return $next($request);
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

    private function isBusinessDay(Carbon $date): bool
    {
        // Fim de semana
        if ($date->isWeekend()) {
            return false;
        }

        // Feriado nacional
        if ($this->isHoliday($date)) {
            return false;
        }

        return true;
    }

    private function isHoliday(Carbon $date): bool
    {
        $year     = $date->year;
        $holidays = $this->getHolidays($year);

        return in_array($date->format('Y-m-d'), $holidays);
    }

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

    private function getEasterDate(int $year): Carbon
    {
        $days = easter_days($year);

        return Carbon::createFromDate($year, 3, 21)->addDays($days);
    }
}
