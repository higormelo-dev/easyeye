<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tira de audit_logs o hash do código de verificação do WhatsApp (sha256 de
 * 6 dígitos — reversível por força bruta) e os contadores do código, que o
 * Auditable do User gravava a cada envio. Agora estão em User::$auditExclude.
 *
 * Só as linhas do User e só essas três chaves: o resto da trilha (inclusive
 * phone_verified_at) fica intacto. Sem volta (down vazio): o hash apagado
 * não deve voltar.
 *
 * Portável: o JSON é filtrado em PHP (sem operador jsonb), em lotes.
 */
return new class() extends Migration {
    private const USER_CLASS = 'App\\Models\\User';

    private const KEYS = ['phone_verification_code', 'phone_verification_expires_at', 'phone_verification_attempts'];

    public function up(): void
    {
        DB::table('audit_logs')
            ->where('auditable_type', self::USER_CLASS)
            ->select(['id', 'old_values', 'new_values'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach (['old_values', 'new_values'] as $column) {
                        $values = is_string($row->{$column}) ? json_decode($row->{$column}, true) : null;

                        if (is_array($values) && array_intersect_key($values, array_flip(self::KEYS)) !== []) {
                            $changes[$column] = json_encode(array_diff_key($values, array_flip(self::KEYS)));
                        }
                    }

                    if ($changes !== []) {
                        DB::table('audit_logs')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    public function down(): void
    {
        // Irreversível de propósito: o hash do código não volta para a trilha.
    }
};
