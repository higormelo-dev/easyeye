<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

/**
 * Modelos adotados pelas clínicas × atualização do modelo padrão.
 *
 * Antes, sincronizar as cópias (ReportSettingContentSeeder, AdoptGlobal…,
 * `reports:sync-adopted`) sobrescrevia QUALQUER conteúdo da cópia diferente
 * do global — inclusive os que a clínica personalizou.
 *
 *  - `source_content_hash`: impressão (sha256 de rótulo + conteúdo) da versão
 *    do modelo padrão de que a cópia veio. Nulo = personalizado pela clínica
 *    (ou conteúdo próprio). A sincronização só troca o texto quando a cópia
 *    continua igual a essa versão.
 *  - `dismissed_source_hash`: "Manter o meu" — versão do padrão que a clínica
 *    dispensou; o aviso volta quando o padrão mudar de novo.
 *
 * Backfill (roda ANTES dos seeds no deploy, com os globais ainda antigos):
 * cópia igual ao global atual → grava o hash; diferente → fica nula
 * (personalizada, nunca sobrescrita).
 */
return new class() extends Migration {
    public function up(): void
    {
        Schema::table('report_setting_contents', function (Blueprint $table) {
            $table->string('source_content_hash', 64)->nullable();
            $table->string('dismissed_source_hash', 64)->nullable();
        });

        $fingerprint = static fn (?string $label, ?string $content): string => hash('sha256', ($label ?? '') . "\0" . ($content ?? ''));

        // Conteúdos dos modelos padrão (globais), por modelo e slug.
        $globals = [];

        DB::table('report_setting_contents as c')
            ->join('report_settings as s', 's.id', '=', 'c.report_setting_id')
            ->whereNull('s.entity_id')
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.slug')
            ->orderBy('c.created_at')
            ->select('c.report_setting_id', 'c.slug', 'c.label', 'c.content')
            ->get()
            ->each(function ($row) use (&$globals, $fingerprint): void {
                $globals[$row->report_setting_id][$row->slug] ??= $fingerprint($row->label, $row->content);
            });

        DB::table('report_setting_contents as c')
            ->join('report_settings as s', 's.id', '=', 'c.report_setting_id')
            ->whereNotNull('s.source_setting_id')
            ->whereNotNull('c.slug')
            ->select('c.id', 'c.slug', 'c.label', 'c.content', 's.source_setting_id')
            ->orderBy('c.id')
            ->chunk(500, function ($rows) use ($globals, $fingerprint): void {
                foreach ($rows as $row) {
                    $global = $globals[$row->source_setting_id][$row->slug] ?? null;

                    if ($global !== null && $global === $fingerprint($row->label, $row->content)) {
                        DB::table('report_setting_contents')->where('id', $row->id)->update(['source_content_hash' => $global]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('report_setting_contents', function (Blueprint $table) {
            $table->dropColumn(['source_content_hash', 'dismissed_source_hash']);
        });
    }
};
