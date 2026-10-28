<?php

// shortcut: caminhos de runtime (storage/S3) mantêm o nome antigo para não deixar estado/objetos órfãos; renomear só com limpeza legada

use App\Domains\AI\Services\AiProviderSettings;
use App\Enums\AI\AiProvider;
use App\Models\{Doctor, Entity, ExamType, MedicalRecord, Patient, People};
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\{Arr, ConfigurationUrlParser};
use Illuminate\Support\Facades\{DB, Schema, Storage};

// Limpeza do cenário CY-REPORTS (Fase 5 — laudos de exame, spec clinic.reports): paciente, exames, imagens,
// rascunhos e documentações — SÓ o que o seed registrou no arquivo de estado (ids persistidos + entity_id),
// NUNCA por nome. Sem estado (ou com estado sem ids válidos) ABORTA sem apagar nada; a limpeza legada por nome
// (restos de versões antigas do seed) só roda com E2E_LEGACY_CLEAN=1 explícito e sempre dentro da clínica de teste.
// ExamType: só o que o seed criou (id guardado); por nome só com E2E_LEGACY_CLEAN=1.
// Devolve os provedores do Manager ao que eram (estado ou backup legado); backup inválido ABORTA antes de apagar.
// NÃO mexe na médica nem na secretária (o seed só valida o vínculo).
//
// Uso (normalmente via seed-exam-manager.php): php artisan tinker --execute="require 'e2e/scripts/clean-exam-manager.php';"

if (! function_exists('eiGuard')) {
    /**
     * Todo destino que a conexão padrão pode alcançar, já RESOLVIDO como o Laravel o resolve: DB_URL passa pelo
     * mesmo ConfigurationUrlParser do DatabaseManager (as opções da QUERY — ?database=…, ?write[host]=… — sobrepõem
     * o caminho da URL) e read/write (única entrada ou LISTA, cada uma podendo trazer host e database próprios) são
     * mesclados sobre a base como o ConnectionFactory faz. A conexão viva (getConfig) é conferida DEPOIS, em
     * eiGuard, mas SÓ ela não basta: o Connection guarda apenas o destino de escrita sorteado e perde os de leitura.
     *
     * @return array<string, array<string, mixed>> rótulo => configuração do destino
     */
    function eiDatabaseTargets(): array
    {
        $name = DB::getDefaultConnection();
        $raw  = config("database.connections.{$name}");

        if (! is_array($raw)) {
            eiAbortGuard("a conexão padrão '{$name}' não tem configuração.");
        }

        try {
            $resolved = (new ConfigurationUrlParser())->parseConfiguration($raw);
        } catch (Throwable $e) {
            eiAbortGuard('não foi possível resolver a URL da conexão (' . class_basename($e) . ').');
        }

        $base    = Arr::except($resolved, ['read', 'write']);
        $targets = ['conexão resolvida' => $base];

        foreach (['read', 'write'] as $type) {
            if (! array_key_exists($type, $resolved)) {
                continue;
            }

            $entries = $resolved[$type];

            if (! is_array($entries)) {
                eiAbortGuard("'{$type}' da conexão não é uma configuração válida.");
            }

            // Lista EXATAMENTE como o ConnectionFactory::getReadWriteConfig: isset($entries[0]) — lista ESPARSA
            // (read[0] e read[2]) também é sorteada, qualquer valor dela pode ser o destino. Sem [0] é uma
            // configuração única mesclada sobre a base. Todas as alternativas são validadas, não só a herdada.
            foreach (isset($entries[0]) ? $entries : [$entries] as $i => $entry) {
                if (! is_array($entry)) {
                    eiAbortGuard("'{$type}[{$i}]' da conexão não é uma configuração válida.");
                }

                $targets["{$type}[{$i}]"] = array_merge($base, $entry);
            }
        }

        return $targets;
    }

    function eiAbortGuard(string $detail): never
    {
        fwrite(STDERR, "ABORTADO: seed/clean do e2e só rodam em APP_ENV local/testing, com TODO destino da conexão EFETIVA (DB_URL e suas opções, host, read[], write[]) em host local e banco da lista E2E_ALLOWED_DATABASES — {$detail}\n");

        exit(1);
    }

    /**
     * Só roda em ambiente local/testing, com TODOS os destinos da conexão efetiva (eiDatabaseTargets) em host local
     * e num banco da lista (E2E_ALLOWED_DATABASES); senão, aborta com exit 1 antes de tocar em qualquer coisa.
     */
    function eiGuard(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            eiAbortGuard('env=' . app()->environment() . '.');
        }

        // 1º os destinos da configuração (sem abrir a conexão): um read/write esparso estraga a criação da conexão
        // e o erro tem de ser o do destino fora da lista, não o do framework.
        eiAssertLocalTargets(eiDatabaseTargets());

        try {
            $live = (array) DB::connection(DB::getDefaultConnection())->getConfig();
        } catch (Throwable $e) {
            eiAbortGuard('não foi possível resolver a conexão viva (' . class_basename($e) . ').');
        }

        eiAssertLocalTargets(['conexão viva' => Arr::except($live, ['read', 'write'])]);
    }

    /** @param array<string, array<string, mixed>> $targets */
    function eiAssertLocalTargets(array $targets): void
    {
        $allowedDb = array_filter(array_map('trim', explode(',', (string) env('E2E_ALLOWED_DATABASES', 'easyeye,easyeye_test,easyeye_e2e'))));

        foreach ($targets as $label => $target) {
            $database = (string) ($target['database'] ?? '');
            $hosts    = array_values(array_filter(
                array_map(fn ($host) => mb_strtolower(trim((string) $host)), Arr::flatten(Arr::wrap($target['host'] ?? []))),
                fn (string $host) => $host !== '',
            ));

            if ($hosts === [] || array_diff($hosts, ['127.0.0.1', 'localhost', '::1']) !== [] || ! in_array($database, $allowedDb, true)) {
                eiAbortGuard("destino '{$label}': hosts=" . implode('|', $hosts) . " banco={$database} (permitidos: [" . implode(', ', $allowedDb) . ']).');
            }
        }
    }

    function eiFail(string $message): never
    {
        fwrite(STDERR, "ERRO: {$message}\n");

        exit(1);
    }

    function eiStatePath(): string
    {
        return storage_path('app/e2e-eye-images-state.json');
    }

    /** @return array<string, mixed>|null null = arquivo ausente; array vazio = arquivo ilegível/inválido */
    function eiState(): ?array
    {
        if (! is_file(eiStatePath())) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents(eiStatePath()), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Grava o estado de forma VERIFICADA e atômica: arquivo temporário na mesma pasta + rename, conferindo o
     * retorno de cada passo e relendo. Qualquer falha LANÇA exceção (não sai): quem chama de dentro de uma
     * transação deixa o rollback acontecer antes de abortar (eiRecord).
     *
     * E2E_SIMULATE_STATE_FAILURE=<chave> faz a gravação que traz essa chave falhar, como um disco cheio: serve
     * só para PROVAR o rollback do seed; vazio em qualquer uso normal.
     *
     * @param array<string, mixed> $merge
     */
    function eiWriteState(array $merge): void
    {
        $simulate = (string) (getenv('E2E_SIMULATE_STATE_FAILURE') ?: '');

        if ($simulate !== '' && array_key_exists($simulate, $merge)) {
            throw new RuntimeException("falha simulada (E2E_SIMULATE_STATE_FAILURE={$simulate}) ao gravar o estado do cenário.");
        }

        $data = array_merge(eiState() ?? [], $merge);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $tmp  = eiStatePath() . '.tmp.' . getmypid();

        if (@file_put_contents($tmp, $json, LOCK_EX) !== strlen($json)) {
            @unlink($tmp);

            throw new RuntimeException('não foi possível gravar o estado do cenário em ' . dirname(eiStatePath()) . ' (diretório sem permissão de escrita?).');
        }

        if (! @rename($tmp, eiStatePath())) {
            @unlink($tmp);

            throw new RuntimeException('não foi possível substituir o arquivo de estado (rename falhou).');
        }

        if (json_decode((string) @file_get_contents(eiStatePath()), true) !== $data) {
            throw new RuntimeException('o arquivo de estado gravado não confere com o esperado na releitura.');
        }
    }

    /**
     * Grava o estado ANTES de qualquer put ou criação, para nunca deixar objeto (bucket ou banco) que o clean não
     * saiba apagar. Falha ABORTA (exit 1).
     *
     * @param array<string, mixed> $merge
     */
    function eiSaveState(array $merge): void
    {
        try {
            eiWriteState($merge);
        } catch (Throwable $e) {
            eiFail($e->getMessage() . ' — nada foi criado.');
        }
    }

    /**
     * Cria um registro do banco e grava o id dele no estado NA MESMA TRANSAÇÃO: se a criação ou a gravação do
     * estado falhar, o registro é desfeito (rollback) e o seed aborta — nunca sobra linha que o clean não conheça.
     * O retorno de $create é entregue a $state, que devolve o trecho do estado a gravar.
     *
     * @param callable(): mixed                     $create
     * @param callable(mixed): array<string, mixed> $state
     */
    function eiRecord(string $what, callable $create, callable $state): mixed
    {
        try {
            return DB::transaction(function () use ($create, $state) {
                $record = $create();
                eiWriteState($state($record));

                return $record;
            });
        } catch (Throwable $e) {
            eiFail("falha ao criar e registrar {$what} no estado (" . class_basename($e) . ': ' . $e->getMessage() . ') — o registro foi desfeito (rollback).');
        }
    }

    /** Algo ainda aponta para a pessoa? (paciente, médico — apagados incluídos — ou conta/vínculo do portal do paciente) */
    function eiPersonStillLinked(string $personId): bool
    {
        return Patient::withTrashed()->where('person_id', $personId)->exists()
            || Doctor::withTrashed()->where('person_id', $personId)->exists()
            || (Schema::hasTable('patient_accounts') && DB::table('patient_accounts')->where('person_id', $personId)->exists())
            || (Schema::hasTable('patient_account_links') && DB::table('patient_account_links')->where('person_id', $personId)->exists());
    }

    /** Remove a pessoa do cenário (pelo id) se nada mais a usa e se ela ainda é a do cenário (nome); senão avisa e deixa. */
    function eiForgetPerson(string $personId): void
    {
        $person = People::withTrashed()->find($personId);

        if (! $person) {
            return;
        }

        if ($person->full_name !== 'CY-REPORTS PACIENTE') {
            fwrite(STDERR, "AVISO: a pessoa {$personId} do estado não tem o nome do cenário — não foi removida.\n");

            return;
        }

        if (! eiPersonStillLinked($personId)) {
            $person->forceDelete();
        }
    }

    /** Papéis de provedores como o painel os guarda: EXATAMENTE as chaves de papel, cada uma vazia ou um provedor conhecido. */
    function eiValidRoles(mixed $roles): bool
    {
        if (! is_array($roles) || array_diff(array_keys($roles), AiProviderSettings::ROLE_KEYS) !== [] || array_diff(AiProviderSettings::ROLE_KEYS, array_keys($roles)) !== []) {
            return false;
        }

        foreach ($roles as $code) {
            if ($code !== null && (! is_string($code) || AiProvider::tryFrom($code) === null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Disco do cenário. Padrão: o disco LOCAL dedicado (nunca o bucket remoto). Só com E2E_EXAM_DISK=s3
     * explícito no ambiente do comando o seed usa o disco que a tela serve ("s3"), sob o prefixo
     * e2e/eye-images/<run-id>/ — o clean apaga apenas os caminhos que o seed registrou no estado.
     */
    function eiDisk(string $name = 'local'): Filesystem
    {
        return $name === 's3'
            ? Storage::disk('s3')
            : Storage::build(['driver' => 'local', 'root' => storage_path('app/e2e-eye-images'), 'throw' => true]);
    }

    /** Disco pedido para os arquivos de exame: 'local' (padrão) ou 's3' (só com E2E_EXAM_DISK=s3 explícito). */
    function eiRequestedDisk(): string
    {
        $value = getenv('E2E_EXAM_DISK') ?: ($_SERVER['E2E_EXAM_DISK'] ?? '');

        return $value === 's3' ? 's3' : 'local';
    }
}

eiGuard();

$state       = eiState();
$legacyClean = (getenv('E2E_LEGACY_CLEAN') ?: '') === '1';
$preclean    = $eiSeedPreclean ?? false; // o seed marca: "sem estado" na 1ª execução é normal (nada a limpar)
$ent         = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->first();

if ($state === null && ! $legacyClean) {
    if ($preclean) {
        return; // 1ª execução do seed: não há o que limpar
    }

    eiFail('sem arquivo de estado (' . eiStatePath() . '): nada foi apagado. Limpeza por nome (restos de versões antigas) só com E2E_LEGACY_CLEAN=1 explícito.');
}

if ($state !== null && (! is_string($state['entity_id'] ?? null) || $state['entity_id'] === '') && ! $legacyClean) {
    eiFail('o arquivo de estado não tem ids válidos (entity_id): nada foi apagado. Corrija/remova o arquivo, ou use E2E_LEGACY_CLEAN=1 explícito.');
}

// Provedores do Manager: o que restaurar é decidido e VALIDADO aqui, antes de qualquer delete — estado com
// roles_before inválido, ou backup LEGADO (versão antiga do seed) ilegível/inválido, ABORTA sem apagar nada
// (nem o arquivo legado, que seria a única cópia da configuração original).
$legacyBackup = storage_path('app/e2e-eye-images-roles.json');
$rolesBefore  = null;

if (array_key_exists('roles_before', (array) $state)) {
    if (! eiValidRoles($state['roles_before'])) {
        eiFail('o estado tem roles_before inválido (' . eiStatePath() . '): nada foi apagado. Corrija o arquivo à mão — ele guarda a configuração original dos provedores.');
    }

    $rolesBefore = $state['roles_before'];
}

if (is_file($legacyBackup)) {
    $legacyRoles = json_decode((string) @file_get_contents($legacyBackup), true);

    if (! eiValidRoles($legacyRoles)) {
        eiFail("o backup legado dos provedores ({$legacyBackup}) está ilegível ou inválido: nada foi apagado. Corrija ou remova o arquivo à mão — ele pode ser a única cópia da configuração original.");
    }

    $rolesBefore ??= $legacyRoles;
}

$entityId   = is_string($state['entity_id'] ?? null) ? $state['entity_id'] : $ent?->id;
$patientIds = filled($state['patient_id'] ?? null) ? [$state['patient_id']] : [];

if ($legacyClean && $patientIds === [] && $ent) {
    // LEGADO, só com a flag: pelo nome E DENTRO da clínica de teste.
    $patientIds = Patient::withTrashed()->where('entity_id', $ent->id)
        ->whereHas('person', fn ($q) => $q->withTrashed()->where('full_name', 'CY-REPORTS PACIENTE'))->pluck('id')->all();
    $entityId = $ent->id;
}

$personIds = filled($state['person_id'] ?? null) ? [$state['person_id']] : [];

foreach ($patientIds as $patientId) {
    $pat = Patient::withTrashed()->where('id', $patientId)->where('entity_id', $entityId)->first();

    if (! $pat) {
        continue;
    }

    DB::table('eye_image_report_drafts')->where('patient_id', $pat->id)->where('entity_id', $entityId)->delete();

    foreach (MedicalRecord::withTrashed()->where('patient_id', $pat->id)->where('entity_id', $entityId)->get() as $r) {
        DB::table('medical_record_documentations')->where('medical_record_id', $r->id)->delete();
        DB::table('medical_record_files')->where('medical_record_id', $r->id)->delete();
        $r->forceDelete();
    }

    DB::table('patient_exams')->where('patient_id', $pat->id)->delete();
    DB::table('schedules')->where('patient_id', $pat->id)->where('entity_id', $entityId)->delete();
    $personIds[] = $pat->person_id;
    $pat->forceDelete();
}

// A pessoa do cenário sai pelo id que o seed gravou no estado (person_id, mesmo que o paciente já não exista)
// e pelo vínculo de cada paciente apagado acima — só sem NENHUM outro vínculo, nunca a de um homônimo.
foreach (array_unique(array_filter($personIds)) as $personId) {
    eiForgetPerson((string) $personId);
}

// ExamType tem soft delete: sem forceDelete sobram linhas apagadas. Só o tipo que ESTE seed criou, pelo id
// guardado (e dentro da clínica do estado); restos APAGADOS com o nome do cenário (versões antigas, sem id)
// só com E2E_LEGACY_CLEAN=1 explícito e dentro da clínica de teste.
if (($state['exam_type_created'] ?? false) === true && filled($state['exam_type_id'] ?? null) && filled($entityId)) {
    ExamType::withTrashed()->where('id', $state['exam_type_id'])->where('entity_id', $entityId)->forceDelete();
}

if ($legacyClean && $ent) {
    ExamType::onlyTrashed()->where('entity_id', $ent->id)->where('name', 'CY-REPORTS RETINOGRAFIA')->forceDelete();
}

// Arquivos do cenário: SÓ os caminhos que o seed registrou no estado (nunca listagem por prefixo), cada um no
// disco em que foi gravado e dentro do prefixo do cenário. Se um objeto registrado continuar lá, falha alto.
$leftover = [];

foreach ((array) ($state['files'] ?? []) as $file) {
    $disk = is_array($file) ? (string) ($file['disk'] ?? 'local') : 'local'; // formato antigo: string = local
    $path = is_array($file) ? (string) ($file['path'] ?? '') : (string) $file;
    $ok   = $disk === 's3' ? str_starts_with($path, 'e2e/eye-images/') : str_starts_with($path, 'cy-reports/');

    if (! $ok || $path === '') {
        continue;
    }

    try {
        eiDisk($disk)->delete($path);

        if (eiDisk($disk)->exists($path)) {
            $leftover[] = "{$disk}:{$path}";
        }
    } catch (Throwable $e) {
        $leftover[] = "{$disk}:{$path} (" . class_basename($e) . ')';
    }
}

// Provedores do Manager: volta ao que estava antes do seed (estado ou backup LEGADO, ambos já VALIDADOS no
// topo) ANTES de apagar qualquer arquivo de controle.
if ($rolesBefore !== null) {
    app(AiProviderSettings::class)->setRoleAssignments($rolesBefore);
}

if ($leftover === []) {
    @unlink(eiStatePath());
    @unlink($legacyBackup); // só depois de restaurar os provedores acima
} else {
    // Mantém o estado: a próxima limpeza tenta de novo os mesmos caminhos.
    eiFail('objeto(s) do cenário NÃO foram apagados: ' . implode(', ', $leftover));
}
