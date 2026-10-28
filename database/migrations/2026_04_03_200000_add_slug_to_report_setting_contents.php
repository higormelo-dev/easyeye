<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class() extends Migration {
    public function up(): void
    {
        Schema::table('report_setting_contents', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->after('type');
            $table->boolean('is_system')->default(false)->after('active');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('is_system');
        });

        // Backfill: gera slug a partir do label existente (normaliza acentos e caracteres especiais)
        foreach (DB::table('report_setting_contents')->whereNotNull('label')->get() as $row) {
            $slug = self::toSlug($row->label);
            DB::table('report_setting_contents')
                ->where('id', $row->id)
                ->update(['slug' => $slug, 'is_system' => true]);
        }

        Schema::table('report_setting_contents', function (Blueprint $table) {
            $table->unique(['report_setting_id', 'slug'], 'uq_content_slug');
        });
    }

    public function down(): void
    {
        Schema::table('report_setting_contents', function (Blueprint $table) {
            $table->dropUnique('uq_content_slug');
            $table->dropColumn(['slug', 'is_system', 'sort_order']);
        });
    }

    private static function toSlug(string $label): string
    {
        $map = [
            'ã' => 'a', 'â' => 'a', 'á' => 'a', 'à' => 'a', 'ä' => 'a',
            'ê' => 'e', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
            'î' => 'i', 'í' => 'i', 'ì' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ö' => 'o',
            'û' => 'u', 'ú' => 'u', 'ù' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];

        $lower = mb_strtolower($label);
        $ascii = strtr($lower, $map);
        // remove parênteses, traços, barras; espaços viram underscore
        $slug = preg_replace('/[^a-z0-9]+/', '_', $ascii);

        return trim($slug, '_');
    }
};
