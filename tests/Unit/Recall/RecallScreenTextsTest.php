<?php

declare(strict_types=1);

/**
 * Contrato dos textos da tela "Retornos" (Fase 6): pt_BR e en têm as mesmas chaves, toda chave que a página
 * Vue pede existe nos dois idiomas, e os rótulos de enum, de motivo de encerramento e de evento cobrem tudo o que o
 * servidor pode gravar. Chave que falta apareceria na tela como o próprio caminho ("row.contact_never").
 */

use App\Domains\Recall\Engine\RecallState;
use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus, IntervalUnit, ProtocolVersionState, RecallBlockReason, RecallContactOutcome, RecallLifecycle};
use App\Domains\Recall\Exceptions\{PatientConditionException, ProtocolActivationException, RecallActionException};
use App\Enums\Eye;
use Illuminate\Support\Arr;
use Tests\TestCase;

uses(TestCase::class);

const RST_PAGE = 'resources/js/Pages/Panel/Recall/Index.vue';

const RST_SETTINGS_PAGE = 'resources/js/Pages/Panel/Recall/Settings.vue';

const RST_CONDITIONS_CARD = 'resources/js/Components/Panel/Recall/PatientConditionsCard.vue';

function rstTexts(string $locale, string $group): array
{
    return require lang_path("{$locale}/{$group}.php");
}

it('pt_BR e en têm exatamente as mesmas chaves nos arquivos do recall', function (string $group) {
    $pt = array_keys(Arr::dot(rstTexts('pt_BR', $group)));
    $en = array_keys(Arr::dot(rstTexts('en', $group)));

    expect(array_diff($pt, $en))->toBe([], "faltam em en/{$group}.php")
        ->and(array_diff($en, $pt))->toBe([], "faltam em pt_BR/{$group}.php");
})->with(['recall', 'recall_list', 'recall_settings', 'recall_conditions', 'manager_recall', 'recall_protocol', 'recall_rule']);

it('toda chave tx() e t.* da página existe em pt_BR e en', function (string $page, string $group) {
    $source = (string) file_get_contents(base_path($page));

    preg_match_all('/\btx\(\s*[\'"]([A-Za-z0-9_.]+)[\'"]/', $source, $static);
    preg_match_all('/(?<![\w.])t\.([A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*)/', $source, $direct);

    $paths = array_values(array_unique([...$static[1], ...$direct[1]]));

    expect($paths)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rstTexts($locale, $group);

        foreach ($paths as $path) {
            expect(Arr::has($texts, $path))->toBeTrue("{$locale}/{$group}.php não tem '{$path}'");
        }
    }
})->with([
    'Retornos'   => [RST_PAGE, 'recall_list'],
    'Configurar' => [RST_SETTINGS_PAGE, 'recall_settings'],
    'Condições'  => [RST_CONDITIONS_CARD, 'recall_conditions'],
]);

it('as chaves montadas em tempo de execução cobrem todos os valores possíveis', function () {
    $source = (string) file_get_contents(base_path(RST_PAGE));

    preg_match_all('/\btx\(\s*`([A-Za-z0-9_.]*)\$\{/', $source, $matches);
    $prefixes = array_unique($matches[1]);

    expect($prefixes)->toContain('tabs.', 'modal.');

    $views   = ['to_contact', 'lost', 'scheduled', 'snoozed', 'history', 'needs_info', 'suspended'];
    $dialogs = ['contact', 'snooze', 'dismiss', 'done_elsewhere', 'reopen'];

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rstTexts($locale, 'recall_list');

        foreach ($views as $view) {
            expect($texts['tabs'][$view] ?? null)->toBeString("{$locale} tabs.{$view}");
        }

        foreach ($dialogs as $dialog) {
            expect($texts['modal'][$dialog]['title'] ?? null)->toBeString("{$locale} modal.{$dialog}.title")
                ->and($texts['modal'][$dialog]['submit'] ?? null)->toBeString("{$locale} modal.{$dialog}.submit");
        }

        // Os itens indexados por valor do servidor: olho e estado.
        foreach (['right', 'left', 'both'] as $eye) {
            expect($texts['row']['eye'][$eye] ?? null)->toBeString("{$locale} row.eye.{$eye}");
        }

        foreach (['to_contact_title', 'to_contact', 'filtered_title', 'filtered', 'none_title', 'none', 'doctor_order_off'] as $key) {
            expect($texts['empty'][$key] ?? null)->toBeString("{$locale} empty.{$key}");
        }
    }
});

it('os rótulos de enum do recall cobrem todos os valores, nos dois idiomas', function () {
    foreach (['pt_BR', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach ([RecallState::cases(), RecallLifecycle::cases(), RecallContactOutcome::cases(), ConditionStatus::cases(), ConditionOrigin::cases(), RecallBlockReason::cases()] as $cases) {
            foreach ($cases as $case) {
                expect($case->label())->toBeString();
                $this->assertStringNotContainsString('recall.', $case->label(), "{$locale} {$case->value} vazou a chave de tradução");
            }
        }
    }
});

it('todo motivo de encerramento e todo evento que o servidor grava tem rótulo', function () {
    $sources = [
        file_get_contents(app_path('Domains/Recall/Services/RecallEvaluator.php')),
        file_get_contents(app_path('Domains/Recall/Services/RecallActionService.php')),
    ];

    preg_match_all("/->event\(\\\$row, '([a-z_]+)'/", implode("\n", $sources), $events);

    // Os eventos do convite por WhatsApp são gravados direto (create) pelo serviço de envio e pelo respondedor.
    $inviteSources = file_get_contents(app_path('Domains/Recall/Services/RecallInviteService.php')) . file_get_contents(app_path('Domains/Recall/Services/RecallInviteResponder.php'));
    preg_match_all("/'event'\s*=>\s*'([a-z_]+)'/", $inviteSources, $inviteEvents);
    $events[1] = [...$events[1], ...$inviteEvents[1]];
    preg_match_all("/(?:'|=> ')((?:completed|condition_ended|protocol_deactivated|task_disabled|doctor_order_ended|doctor_order_disabled|done_elsewhere|patient_removed))'/", implode("\n", $sources), $reasons);

    $events  = array_values(array_unique($events[1]));
    $reasons = array_values(array_unique($reasons[1]));

    expect($events)->not->toBeEmpty()
        ->and($reasons)->toHaveCount(8);

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rstTexts($locale, 'recall');

        foreach ($events as $event) {
            expect($texts['events'][$event] ?? null)->toBeString("{$locale} events.{$event}");
        }

        foreach ($reasons as $reason) {
            expect($texts['closed_reason'][$reason] ?? null)->toBeString("{$locale} closed_reason.{$reason}");
        }
    }
});

it('todo código de recusa da ação da equipe tem mensagem na tela', function () {
    $codes = array_values(array_filter(
        (new ReflectionClass(RecallActionException::class))->getConstants(),
        'is_string',
    ));

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rstTexts($locale, 'recall_list');

        foreach ($codes as $code) {
            expect($texts['errors'][$code] ?? null)->toBeString("{$locale} errors.{$code}");
        }
    }

    expect($codes)->not->toBeEmpty();
});

it('o espelho em JSON dos textos (usado pelo Vitest) é idêntico ao pt_BR/recall_list.php', function () {
    $mirror = json_decode((string) file_get_contents(base_path('tests/JavaScript/Panel/Recall/support/recall_list.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($mirror)->toBe(rstTexts('pt_BR', 'recall_list'), 'regere com: php scripts/dump-lang-json.php recall_list tests/JavaScript/Panel/Recall/support/recall_list.json');
});

it('Configurar retornos: as chaves montadas em tempo de execução cobrem todos os valores que o servidor produz', function () {
    $page      = (string) file_get_contents(base_path(RST_SETTINGS_PAGE));
    $presenter = (string) file_get_contents(app_path('Domains/Recall/Services/RecallSettingsPresenter.php'));

    // O que a página monta com ${...} ou colchetes: a leitura do código garante que ninguém acrescenta um valor sem texto.
    preg_match_all('/\btx\(\s*`([A-Za-z0-9_.]*)\$\{/', $page, $matches);
    expect(array_unique($matches[1]))->toEqualCanonicalizing(['protocols.form.units.', 'protocols.form.submit_', 'protocols.form.title_']);
    expect($page)->toContain('t.protocols.status[protocol.status]')->and($page)->toContain('t.protocols.paused[');

    // Estados do protocolo na clínica: o `match` do apresentador.
    expect(preg_match('/\$status = match \(true\) \{(.*?)\};/s', $presenter, $block))->toBe(1);
    preg_match_all("/=>\s*'([a-z_]+)'/", $block[1], $statuses);
    $statuses = array_values(array_unique($statuses[1]));

    // Motivos de parada: versão fora de uso (estado) + integridade + conteúdo diferente do adotado.
    $pauseReasons = [
        ...array_map(static fn (ProtocolVersionState $state): string => $state->value, array_filter(ProtocolVersionState::cases(), static fn (ProtocolVersionState $state): bool => ! $state->isInUse())),
        'integrity',
        'hash',
    ];

    $units = array_map(static fn (IntervalUnit $unit): string => $unit->value, IntervalUnit::cases());

    expect($statuses)->toEqualCanonicalizing(['paused', 'update_available', 'active', 'available', 'unavailable']);

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rstTexts($locale, 'recall_settings');

        foreach ($statuses as $status) {
            expect($texts['protocols']['status'][$status] ?? null)->toBeString("{$locale} protocols.status.{$status}");
        }

        foreach ($pauseReasons as $reason) {
            expect($texts['protocols']['paused'][$reason] ?? null)->toBeString("{$locale} protocols.paused.{$reason}");
        }

        foreach ($units as $unit) {
            expect($texts['protocols']['form']['units'][$unit] ?? null)->toBeString("{$locale} protocols.form.units.{$unit}");
        }

        foreach (['activate', 'adjust', 'upgrade'] as $mode) {
            expect($texts['protocols']['form']["submit_{$mode}"] ?? null)->toBeString("{$locale} protocols.form.submit_{$mode}")
                ->and($texts['protocols']['form']["title_{$mode}"] ?? null)->toBeString("{$locale} protocols.form.title_{$mode}");
        }

        expect($texts['protocols']['update']['text_paused'] ?? null)->toBeString("{$locale} protocols.update.text_paused");
    }
});

it('Configurar retornos: todo código de recusa (ativação, parâmetro, exame) e toda chave usada pelo PHP tem texto', function () {
    $codes = array_values(array_filter((new ReflectionClass(ProtocolActivationException::class))->getConstants(), 'is_string'));

    // Códigos por campo: o serviço grava `$errors[...] = 'codigo'` e a tela mostra `errors.<codigo>`.
    $service = (string) file_get_contents(app_path('Domains/Recall/Services/ProtocolActivationService.php'));
    preg_match_all('/\$errors\[[^\]]+\]\s*=\s*\'([a-z_]+)\'/', $service, $fieldCodes);

    // Chaves de `recall_settings.*` escritas literalmente no PHP (controller, requests, serviços).
    $used = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/recall_settings\.([a-z_]+(?:\.[a-z_]+)*)(?![a-z_.])/', (string) file_get_contents($file->getPathname()), $found);
        array_push($used, ...$found[1]);
    }

    expect($codes)->not->toBeEmpty()
        ->and($fieldCodes[1])->toContain('interval_out_of_range', 'unknown_task');

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rstTexts($locale, 'recall_settings');

        foreach ([...$codes, ...$fieldCodes[1], 'unknown_exam_key', 'invalid_mapping'] as $code) {
            expect($texts['errors'][$code] ?? null)->toBeString("{$locale} errors.{$code}");
        }

        foreach (array_unique($used) as $key) {
            expect(Arr::has($texts, $key))->toBeTrue("{$locale}/recall_settings.php não tem '{$key}' (usada no PHP)");
        }
    }
});

it('o espelho em JSON dos textos de Configurar retornos é idêntico ao pt_BR/recall_settings.php', function () {
    $mirror = json_decode((string) file_get_contents(base_path('tests/JavaScript/Panel/Recall/support/recall_settings.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($mirror)->toBe(rstTexts('pt_BR', 'recall_settings'), 'regere com: php scripts/dump-lang-json.php recall_settings tests/JavaScript/Panel/Recall/support/recall_settings.json');
});

it('Condições crônicas: textos dinâmicos, códigos de recusa, evidência, olhos e medicações cobrem tudo o que o servidor produz', function () {
    $card      = (string) file_get_contents(base_path(RST_CONDITIONS_CARD));
    $presenter = (string) file_get_contents(app_path('Domains/Recall/Services/PatientConditionsPresenter.php'));

    // A única chave montada em tempo de execução no cartão: a decisão no histórico (só estados já decididos).
    preg_match_all('/\btx\(\s*`([A-Za-z0-9_.]*)\$\{/', $card, $matches);
    expect(array_unique($matches[1]))->toEqualCanonicalizing(['decided.']);

    $decided  = array_values(array_map(static fn (ConditionStatus $status): string => $status->value, array_filter(ConditionStatus::cases(), static fn (ConditionStatus $status): bool => ! $status->isOpen())));
    $codes    = array_values(array_filter((new ReflectionClass(PatientConditionException::class))->getConstants(), 'is_string'));
    $medKeys  = array_keys((array) config('recall.medications'));
    $eyes     = array_map(static fn (Eye $eye): string => $eye->value, Eye::cases());
    $evidence = [];

    // As frases de evidência que o apresentador pede por chave literal.
    preg_match_all("/recall_conditions\.evidence\.([a-z_]+)/", $presenter, $found);
    $evidence = array_values(array_unique($found[1]));

    // Todo texto que o PHP pede, por chave literal (controller, apresentador, requests, serviços).
    $used = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all('/recall_conditions\.([a-z_]+(?:\.[a-z_]+)*)(?![a-z_.])/', (string) file_get_contents($file->getPathname()), $keys);
        array_push($used, ...$keys[1]);
    }

    expect($decided)->toEqualCanonicalizing(['discharged', 'rejected'])
        ->and($codes)->not->toBeEmpty()
        ->and($evidence)->toEqualCanonicalizing(['cid_signed', 'cid_unsigned', 'medication', 'medication_unconfirmed', 'manual', 'unknown_date'])
        ->and($medKeys)->not->toBeEmpty();

    foreach (['pt_BR', 'en'] as $locale) {
        $texts = rstTexts($locale, 'recall_conditions');

        foreach ($decided as $status) {
            expect($texts['decided'][$status] ?? null)->toBeString("{$locale} decided.{$status}");
        }

        foreach ($codes as $code) {
            expect($texts['errors'][$code] ?? null)->toBeString("{$locale} errors.{$code}");
        }

        foreach ($evidence as $key) {
            expect($texts['evidence'][$key] ?? null)->toBeString("{$locale} evidence.{$key}");
        }

        foreach ($eyes as $eye) {
            expect($texts['eye'][$eye] ?? null)->toBeString("{$locale} eye.{$eye}");
        }

        // Medicação sem rótulo apareceria como a chave crua ("hydroxychloroquine"): todo critério de entrada tem nome.
        foreach ($medKeys as $key) {
            expect($texts['medications'][$key] ?? null)->toBeString("{$locale} medications.{$key}");
        }

        foreach (array_unique($used) as $key) {
            expect(Arr::has($texts, $key))->toBeTrue("{$locale}/recall_conditions.php não tem '{$key}' (usada no PHP)");
        }

        // As mensagens de sucesso do controller e a recusa genérica do cartão.
        foreach (['added', 'saved', 'confirmed', 'rejected', 'discharged'] as $done) {
            expect($texts['done'][$done] ?? null)->toBeString("{$locale} done.{$done}");
        }

        expect($texts['errors']['generic'] ?? null)->toBeString("{$locale} errors.generic");
    }
});

it('o espelho em JSON dos textos das condições é idêntico ao pt_BR/recall_conditions.php', function () {
    $mirror = json_decode((string) file_get_contents(base_path('tests/JavaScript/Panel/Recall/support/recall_conditions.json')), true, flags: JSON_THROW_ON_ERROR);

    expect($mirror)->toBe(rstTexts('pt_BR', 'recall_conditions'), 'regere com: php scripts/dump-lang-json.php recall_conditions tests/JavaScript/Panel/Recall/support/recall_conditions.json');
});
