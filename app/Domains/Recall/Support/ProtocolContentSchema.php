<?php

declare(strict_types=1);

namespace App\Domains\Recall\Support;

use App\Domains\Recall\Enums\{IntervalUnit, RecallExamKey, RecallTaskKind};
use App\Domains\Recall\Exceptions\InvalidProtocolContentException;
use App\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Esquema v1 do CONTEÚDO de um protocolo de retorno — o que o Revisor Clínico
 * aprova, o que é congelado e hasheado e o que o avaliador de recall executa.
 *
 * `normalize()` é a única porta: recebe o que o editor mandou, recusa chave
 * desconhecida e tipo errado (nada de "10" no lugar de 10), aplica os padrões
 * operacionais, normaliza (NFC, CID em maiúsculas e sem ponto, conjuntos
 * ordenados e sem repetição) e devolve o conteúdo COMPLETO. O mesmo conteúdo
 * lógico sempre vira os mesmos bytes (CanonicalJson), então hash igual = regra
 * igual. Mudar qualquer regra deste arquivo é mudar o que "válido" quer dizer:
 * nova VERSION do esquema, nunca ajuste silencioso.
 *
 * Formato (todos os campos aparecem no conteúdo normalizado):
 *
 *   schema_version  1
 *   entry           cid {strong[], weak[], exclude[]}  — prefixos de CID-10 sem ponto;
 *                   o prefixo MAIS LONGO que casa decide: strong = condição ativa,
 *                   weak = só sugerida, exclude = fora (ex.: H40 weak + H400 exclude);
 *                   medications[] — chaves de config('recall.medications')
 *   tasks[]         key, kind (consulta/exame/curso), exam_key, interval {unit,
 *                   default, min, max} (min..max é a FAIXA que a clínica pode usar),
 *                   lead_days (convida N dias antes), grace_days (tolerância depois
 *                   do vencimento), lost_after_days (perdido de vista), starts_after_months,
 *                   recurs, required
 *   doctor_order    cap_to_max — limita o retorno que o médico pediu ao máximo validado
 *   references[]    title, year, evidence, note
 *   rationale       justificativa dos números
 *   change_note     o que mudou em relação à versão anterior
 */
final class ProtocolContentSchema
{
    public const VERSION = 1;

    /** Domínio do hash do conteúdo (CanonicalJson::hash). */
    public const HASH_DOMAIN = 'easyeye.recall.protocol.v1';

    public const MAX_CID_PER_LIST = 60;

    public const MAX_MEDICATIONS = 10;

    public const MAX_TASKS = 8;

    public const MAX_REFERENCES = 12;

    public const EVIDENCE_LEVELS = ['high', 'medium', 'low'];

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    private const CID_PATTERN = '/^[A-Z][0-9]{2}[0-9A-Z]{0,3}$/';

    private const DEFAULT_LEAD_DAYS = 30;

    private const DEFAULT_GRACE_DAYS = 30;

    private const DEFAULT_LOST_AFTER_DAYS = 180;

    /** @var array<string, array{0: string, 1: array<string, mixed>}> */
    private array $errors = [];

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed> conteúdo completo e normalizado
     *
     * @throws InvalidProtocolContentException
     */
    public static function normalize(mixed $input): array
    {
        $schema  = new self();
        $content = $schema->run($input);

        if ($schema->errors !== []) {
            throw new InvalidProtocolContentException($schema->errors);
        }

        return $content;
    }

    /** Texto canônico (CanonicalJson) do conteúdo JÁ normalizado. */
    public static function canonical(array $normalized): string
    {
        return CanonicalJson::encode($normalized);
    }

    /** Hash do texto canônico, no domínio dos protocolos. */
    public static function hash(string $canonicalText): string
    {
        return CanonicalJson::hash(self::HASH_DOMAIN, $canonicalText);
    }

    /**
     * Normaliza, escreve e hasheia de uma vez.
     *
     * @return array{content: array<string, mixed>, canonical: string, hash: string}
     *
     * @throws InvalidProtocolContentException
     */
    public static function prepare(mixed $input): array
    {
        $content   = self::normalize($input);
        $canonical = self::canonical($content);

        return ['content' => $content, 'canonical' => $canonical, 'hash' => self::hash($canonical)];
    }

    /**
     * Classifica um CID (com ou sem ponto) pelas listas do conteúdo: o prefixo mais
     * longo que casa decide. Retorna `strong`, `weak`, `exclude` ou null (não casa).
     *
     * @param array<string, mixed> $content conteúdo normalizado
     */
    public static function classifyCid(array $content, ?string $code): ?string
    {
        $code = self::cidKey($code);

        if ($code === null) {
            return null;
        }

        $best       = null;
        $bestLength = 0;

        foreach (['strong', 'weak', 'exclude'] as $list) {
            foreach ((array) ($content['entry']['cid'][$list] ?? []) as $prefix) {
                $length = strlen((string) $prefix);

                if ($length > $bestLength && str_starts_with($code, (string) $prefix)) {
                    $best       = $list;
                    $bestLength = $length;
                }
            }
        }

        return $best;
    }

    /** CID como o protocolo o guarda: maiúsculo, sem ponto, espaço nem †/*; null se não parecer um CID. */
    public static function cidKey(?string $code): ?string
    {
        // †/* são marcas de dupla codificação do CID-10 (E10.3† / H36.0*): não fazem parte do código.
        $key = strtoupper((string) preg_replace('/[\s.†*]+/u', '', (string) $code));

        return preg_match(self::CID_PATTERN, $key) === 1 ? $key : null;
    }

    // ── execução ────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function run(mixed $input): array
    {
        if (! $this->isObject($input)) {
            $this->fail(InvalidProtocolContentException::ROOT, 'not_an_object');

            return [];
        }

        $this->knownKeys('', $input, ['schema_version', 'entry', 'tasks', 'doctor_order', 'references', 'rationale', 'change_note']);

        if (($input['schema_version'] ?? self::VERSION) !== self::VERSION) {
            $this->fail('schema_version', 'schema_version_unsupported');
        }

        $entry       = $this->entry($input['entry'] ?? null);
        $tasks       = $this->tasks($input['tasks'] ?? null);
        $doctorOrder = $this->doctorOrder($input['doctor_order'] ?? []);
        $references  = $this->references($input['references'] ?? []);
        $rationale   = $this->text('rationale', $input['rationale'] ?? null, required: true, max: 2000, multiline: true);
        $changeNote  = $this->text('change_note', $input['change_note'] ?? '', required: false, max: 1000, multiline: true);

        // As regras que olham o conjunto só valem sobre campos sem erro: uma lista de CID
        // mal formada deixaria `strong` vazio e geraria um segundo erro que é só consequência.
        if ($this->errors === []) {
            if ($entry['cid']['strong'] === [] && $entry['medications'] === []) {
                $this->fail('entry', 'no_entry_criteria');
            }

            if (! in_array(true, array_column($tasks, 'required'), true)) {
                $this->fail('tasks', 'no_required_task');
            }
        }

        return [
            'schema_version' => self::VERSION,
            'entry'          => $entry,
            'tasks'          => $tasks,
            'doctor_order'   => $doctorOrder,
            'references'     => $references,
            'rationale'      => $rationale,
            'change_note'    => $changeNote,
        ];
    }

    /** @return array{cid: array{strong: list<string>, weak: list<string>, exclude: list<string>}, medications: list<string>} */
    private function entry(mixed $raw): array
    {
        $empty = ['cid' => ['strong' => [], 'weak' => [], 'exclude' => []], 'medications' => []];

        if (! $this->isObject($raw)) {
            $this->fail('entry', $raw === null ? 'required' : 'not_an_object');

            return $empty;
        }

        $this->knownKeys('entry', $raw, ['cid', 'medications']);

        $cid = $raw['cid'] ?? [];

        if (! $this->isObject($cid)) {
            $this->fail('entry.cid', 'not_an_object');
            $cid = [];
        } else {
            $this->knownKeys('entry.cid', $cid, ['strong', 'weak', 'exclude']);
        }

        $lists = [];

        foreach (['strong', 'weak', 'exclude'] as $list) {
            $lists[$list] = $this->cidList("entry.cid.{$list}", $cid[$list] ?? []);
        }

        // O mesmo prefixo em duas listas é ambíguo (qual vale?). Prefixos DIFERENTES
        // são o uso normal: o mais longo decide.
        foreach (['strong' => ['weak', 'exclude'], 'weak' => ['exclude']] as $first => $others) {
            foreach ($others as $other) {
                foreach (array_intersect($lists[$first], $lists[$other]) as $code) {
                    $this->fail("entry.cid.{$other}", 'cid_conflict', ['value' => $code]);
                }
            }
        }

        return ['cid' => $lists, 'medications' => $this->medications($raw['medications'] ?? [])];
    }

    /** @return list<string> */
    private function cidList(string $path, mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            $this->fail($path, 'not_a_list');

            return [];
        }

        if (count($raw) > self::MAX_CID_PER_LIST) {
            $this->fail($path, 'too_many', ['max' => self::MAX_CID_PER_LIST]);

            return [];
        }

        $codes = [];

        foreach ($raw as $index => $item) {
            $code = is_string($item) ? self::cidKey($item) : null;

            if ($code === null) {
                $this->fail("{$path}.{$index}", 'invalid_cid', ['value' => is_string($item) ? $item : gettype($item)]);

                continue;
            }

            $codes[$code] = true;
        }

        $codes = array_keys($codes);
        sort($codes, SORT_STRING);

        return $codes;
    }

    /** @return list<string> */
    private function medications(mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            $this->fail('entry.medications', 'not_a_list');

            return [];
        }

        if (count($raw) > self::MAX_MEDICATIONS) {
            $this->fail('entry.medications', 'too_many', ['max' => self::MAX_MEDICATIONS]);

            return [];
        }

        $known = array_keys((array) config('recall.medications', []));
        $keys  = [];

        foreach ($raw as $index => $item) {
            if (! is_string($item) || ! in_array($item, $known, true)) {
                $this->fail("entry.medications.{$index}", 'invalid_medication', ['value' => is_string($item) ? $item : gettype($item)]);

                continue;
            }

            $keys[$item] = true;
        }

        $keys = array_keys($keys);
        sort($keys, SORT_STRING);

        return $keys;
    }

    /** @return list<array<string, mixed>> */
    private function tasks(mixed $raw): array
    {
        if ($raw === null) {
            $this->fail('tasks', 'required');

            return [];
        }

        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            $this->fail('tasks', 'not_a_list');

            return [];
        }

        if ($raw === []) {
            $this->fail('tasks', 'too_few', ['min' => 1]);

            return [];
        }

        if (count($raw) > self::MAX_TASKS) {
            $this->fail('tasks', 'too_many', ['max' => self::MAX_TASKS]);

            return [];
        }

        $tasks = [];
        $seen  = [];

        foreach ($raw as $index => $item) {
            $task = $this->task("tasks.{$index}", $item);

            if ($task === null) {
                continue;
            }

            if (isset($seen[$task['key']])) {
                $this->fail("tasks.{$index}.key", 'duplicate', ['value' => $task['key']]);
            }

            $seen[$task['key']] = true;
            $tasks[]            = $task;
        }

        return $tasks;
    }

    /** @return array<string, mixed>|null */
    private function task(string $path, mixed $raw): ?array
    {
        if (! $this->isObject($raw)) {
            $this->fail($path, 'not_an_object');

            return null;
        }

        $this->knownKeys($path, $raw, [
            'key', 'kind', 'exam_key', 'interval', 'lead_days', 'grace_days',
            'lost_after_days', 'starts_after_months', 'recurs', 'required',
        ]);

        $key = $this->key("{$path}.key", $raw['key'] ?? null);

        $kind    = null;
        $kindRaw = $raw['kind'] ?? null;

        if (! is_string($kindRaw)) {
            $this->fail("{$path}.kind", $kindRaw === null ? 'required' : 'must_be_string');
        } else {
            $kind = RecallTaskKind::tryFrom($kindRaw);

            if ($kind === null) {
                $this->fail("{$path}.kind", 'invalid_choice', ['value' => $kindRaw]);
            }
        }

        $examKey = $this->examKey("{$path}.exam_key", $raw['exam_key'] ?? null, $kind);

        $interval = $this->interval("{$path}.interval", $raw['interval'] ?? null);
        $lead     = $this->integer("{$path}.lead_days", $raw['lead_days'] ?? self::DEFAULT_LEAD_DAYS, 0, 365);
        $grace    = $this->integer("{$path}.grace_days", $raw['grace_days'] ?? self::DEFAULT_GRACE_DAYS, 0, 365);
        $lost     = $this->integer("{$path}.lost_after_days", $raw['lost_after_days'] ?? self::DEFAULT_LOST_AFTER_DAYS, 1, 1825);

        if ($lost !== null && $grace !== null && $lost <= $grace) {
            $this->fail("{$path}.lost_after_days", 'lost_not_after_grace');
        }

        $starts = null;

        if (($raw['starts_after_months'] ?? null) !== null) {
            $starts = $this->integer("{$path}.starts_after_months", $raw['starts_after_months'], 1, 600);
        }

        return [
            'key'                 => $key,
            'kind'                => $kind?->value,
            'exam_key'            => $examKey,
            'interval'            => $interval,
            'lead_days'           => $lead,
            'grace_days'          => $grace,
            'lost_after_days'     => $lost,
            'starts_after_months' => $starts,
            'recurs'              => $this->boolean("{$path}.recurs", $raw['recurs'] ?? true),
            'required'            => $this->boolean("{$path}.required", $raw['required'] ?? true),
        ];
    }

    private function key(string $path, mixed $raw): ?string
    {
        if ($raw === null) {
            $this->fail($path, 'required');

            return null;
        }

        if (! is_string($raw)) {
            $this->fail($path, 'must_be_string');

            return null;
        }

        if (preg_match(self::KEY_PATTERN, $raw) !== 1) {
            $this->fail($path, 'invalid_key');

            return null;
        }

        return $raw;
    }

    private function examKey(string $path, mixed $raw, ?RecallTaskKind $kind): ?string
    {
        if ($raw !== null && ! is_string($raw)) {
            $this->fail($path, 'must_be_string');

            return null;
        }

        if ($kind === RecallTaskKind::Exam) {
            if ($raw === null || $raw === '') {
                $this->fail($path, 'exam_key_required');

                return null;
            }

            if (RecallExamKey::tryFrom($raw) === null) {
                $this->fail($path, 'invalid_choice', ['value' => $raw]);

                return null;
            }

            return $raw;
        }

        if ($raw !== null && $raw !== '' && $kind !== null) {
            $this->fail($path, 'exam_key_not_allowed');
        }

        return null;
    }

    /** @return array{unit: ?string, default: ?int, min: ?int, max: ?int} */
    private function interval(string $path, mixed $raw): array
    {
        $empty = ['unit' => null, 'default' => null, 'min' => null, 'max' => null];

        if ($raw === null) {
            $this->fail($path, 'required');

            return $empty;
        }

        if (! $this->isObject($raw)) {
            $this->fail($path, 'not_an_object');

            return $empty;
        }

        $this->knownKeys($path, $raw, ['unit', 'default', 'min', 'max']);

        $unit    = null;
        $unitRaw = $raw['unit'] ?? null;

        if (! is_string($unitRaw)) {
            $this->fail("{$path}.unit", $unitRaw === null ? 'required' : 'must_be_string');
        } else {
            $unit = IntervalUnit::tryFrom($unitRaw);

            if ($unit === null) {
                $this->fail("{$path}.unit", 'invalid_choice', ['value' => $unitRaw]);
            }
        }

        $max = $unit?->maxValue() ?? 1;

        $values = [];

        foreach (['min', 'default', 'max'] as $field) {
            $values[$field] = $unit === null
                ? $this->integer("{$path}.{$field}", $raw[$field] ?? null, 1, PHP_INT_MAX)
                : $this->integer("{$path}.{$field}", $raw[$field] ?? null, 1, $max);
        }

        if (! in_array(null, $values, true) && ! ($values['min'] <= $values['default'] && $values['default'] <= $values['max'])) {
            $this->fail($path, 'interval_order');
        }

        return ['unit' => $unit?->value, 'default' => $values['default'], 'min' => $values['min'], 'max' => $values['max']];
    }

    /** @return array{cap_to_max: bool} */
    private function doctorOrder(mixed $raw): array
    {
        if (! $this->isObject($raw)) {
            $this->fail('doctor_order', 'not_an_object');

            return ['cap_to_max' => true];
        }

        $this->knownKeys('doctor_order', $raw, ['cap_to_max']);

        return ['cap_to_max' => $this->boolean('doctor_order.cap_to_max', $raw['cap_to_max'] ?? true)];
    }

    /** @return list<array{title: string, year: ?int, evidence: string, note: string}> */
    private function references(mixed $raw): array
    {
        if (! is_array($raw) || ($raw !== [] && ! array_is_list($raw))) {
            $this->fail('references', 'not_a_list');

            return [];
        }

        if (count($raw) > self::MAX_REFERENCES) {
            $this->fail('references', 'too_many', ['max' => self::MAX_REFERENCES]);

            return [];
        }

        $references = [];

        foreach ($raw as $index => $item) {
            $path = "references.{$index}";

            if (! $this->isObject($item)) {
                $this->fail($path, 'not_an_object');

                continue;
            }

            $this->knownKeys($path, $item, ['title', 'year', 'evidence', 'note']);

            $evidence = $item['evidence'] ?? null;

            if (! is_string($evidence) || ! in_array($evidence, self::EVIDENCE_LEVELS, true)) {
                $this->fail("{$path}.evidence", $evidence === null ? 'required' : 'invalid_choice', ['value' => is_string($evidence) ? $evidence : gettype($evidence)]);
                $evidence = null;
            }

            $year = ($item['year'] ?? null) === null ? null : $this->integer("{$path}.year", $item['year'], 1900, 2100);

            $references[] = [
                'title'    => $this->text("{$path}.title", $item['title'] ?? null, required: true, max: 240, multiline: false),
                'year'     => $year,
                'evidence' => $evidence,
                'note'     => $this->text("{$path}.note", $item['note'] ?? '', required: false, max: 400, multiline: false),
            ];
        }

        return $references;
    }

    // ── primitivos ──────────────────────────────────────────────────────────

    private function text(string $path, mixed $raw, bool $required, int $max, bool $multiline): string
    {
        if ($raw === null) {
            if ($required) {
                $this->fail($path, 'required');
            }

            return '';
        }

        if (! is_string($raw)) {
            $this->fail($path, 'must_be_string');

            return '';
        }

        try {
            $text = CanonicalJson::normalizeText($raw);
        } catch (InvalidArgumentException) {
            $this->fail($path, 'invalid_utf8');

            return '';
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        $forbidden = $multiline ? '/[\x00-\x08\x0B-\x1F\x7F]/' : '/[\x00-\x1F\x7F]/';

        if (preg_match($forbidden, $text) === 1) {
            $this->fail($path, 'control_characters');

            return '';
        }

        if (mb_strlen($text) > $max) {
            $this->fail($path, 'too_long', ['max' => $max]);

            return '';
        }

        if ($required && $text === '') {
            $this->fail($path, 'required');
        }

        return $text;
    }

    private function integer(string $path, mixed $raw, int $min, int $max): ?int
    {
        if ($raw === null) {
            $this->fail($path, 'required');

            return null;
        }

        if (! is_int($raw)) {
            $this->fail($path, 'must_be_integer');

            return null;
        }

        if ($raw < $min || $raw > $max) {
            $this->fail($path, 'out_of_range', ['min' => $min, 'max' => $max === PHP_INT_MAX ? '∞' : $max]);

            return null;
        }

        return $raw;
    }

    private function boolean(string $path, mixed $raw): bool
    {
        if (! is_bool($raw)) {
            $this->fail($path, 'must_be_boolean');

            return false;
        }

        return $raw;
    }

    /** Objeto = array associativo (ou vazio: o `{}` do JS chega como `[]`). */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * @param array<array-key, mixed> $object
     * @param list<string>            $allowed
     */
    private function knownKeys(string $path, array $object, array $allowed): void
    {
        foreach (array_keys($object) as $key) {
            if (! in_array((string) $key, $allowed, true)) {
                $this->fail($path === '' ? (string) $key : "{$path}.{$key}", 'unknown_field', ['field' => (string) $key]);
            }
        }
    }

    /** @param array<string, mixed> $params */
    private function fail(string $path, string $code, array $params = []): void
    {
        // O primeiro erro de um caminho é o que importa; os seguintes (efeito dominó) ficam de fora.
        $this->errors[$path] ??= [$code, $params];
    }
}
