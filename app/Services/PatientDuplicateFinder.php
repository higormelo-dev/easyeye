<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Paciente duplicado × homônimo, na MESMA clínica (regra única do cadastro
 * completo, da Agenda, do cadastro rápido e da importação):
 *
 *  - mesmo nome (sem acento/maiúsculas, espaços normalizados) + mesma data de
 *    nascimento → duplicado real (bloqueia);
 *  - mesmo nome com nascimento diferente, ou sem nascimento em um dos dois →
 *    homônimo (pede confirmação explícita "é outra pessoa").
 *
 * Paciente excluído (soft delete, do paciente ou do People) não conta — mesmo
 * critério da antiga regra uniqueInClinic('full_name'). Outras clínicas nunca
 * entram (e os códigos devolvidos são só os desta clínica).
 */
final class PatientDuplicateFinder
{
    /**
     * @return array{duplicates: list<string>, homonyms: list<string>} códigos PAC-… existentes
     */
    public function find(string $entityId, string $name, ?string $birthDate, ?string $ignorePersonId = null): array
    {
        $normalized = self::normalizeName($name);

        if ($normalized === '' || $entityId === '') {
            return ['duplicates' => [], 'homonyms' => []];
        }

        $rows = DB::table('patients')
            ->join('people', 'people.id', '=', 'patients.person_id')
            ->where('patients.entity_id', $entityId)
            ->whereNull('patients.deleted_at')
            ->whereNull('people.deleted_at')
            ->when($ignorePersonId, fn ($q) => $q->where('people.id', '!=', $ignorePersonId))
            ->whereRaw("upper(unaccent(regexp_replace(btrim(people.full_name), '\\s+', ' ', 'g'))) = upper(unaccent(?))", [$normalized])
            ->orderBy('patients.code')
            ->get(['patients.code', 'people.birth_date']);

        $birth = self::normalizeDate($birthDate);
        $found = ['duplicates' => [], 'homonyms' => []];

        foreach ($rows as $row) {
            $sameBirth = $birth !== null && self::normalizeDate($row->birth_date) === $birth;

            $found[$sameBirth ? 'duplicates' : 'homonyms'][] = (string) $row->code;
        }

        return $found;
    }

    /** Caixa alta + espaços colapsados (o acento sai no unaccent do banco). */
    public static function normalizeName(?string $name): string
    {
        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $name)), 'UTF-8');
    }

    /**
     * Mudou o que a regra compara (nome normalizado sem acento ou nascimento)?
     * Edição que não mexe nisso não reabre a checagem — homônimo já confirmado
     * (ou duplicado legado) não trava a recepção a cada salvar.
     */
    public static function identityChanged(?string $oldName, mixed $oldBirth, ?string $newName, mixed $newBirth): bool
    {
        $fold = fn (?string $n) => self::normalizeName(Str::ascii((string) $n));

        return $fold($oldName) !== $fold($newName)
            || self::normalizeDate($oldBirth) !== self::normalizeDate($newBirth);
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
