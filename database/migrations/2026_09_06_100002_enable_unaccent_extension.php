<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * BUGFIX (achado de produto, sessão de auditoria de segurança): banco usa
 * collation 'C' (POSIX) — ILIKE só faz case-fold ASCII, não sabe que 'ã'='Ã'.
 * Como People::setAttribute() sempre salva full_name em CAIXA ALTA, qualquer
 * busca digitada com acento minúsculo (o jeito natural de digitar) nunca batia
 * com nomes acentuados salvos (ex.: buscar "João" não achava "JOÃO DA SILVA").
 * Afeta busca de paciente, agenda, médico, usuário, integrador, etc — ~50
 * pontos usando o mesmo padrão ILIKE/LOWER()+LIKE em ~25 controllers.
 *
 * unaccent() é extensão padrão do Postgres (contrib), não precisa instalar
 * pacote extra no SO — normaliza 'ã'/'á'/'â' etc pro equivalente ASCII antes
 * da comparação. Ver App\Support\Database\AccentInsensitiveSearch (macro
 * whereLikeUnaccent/orWhereLikeUnaccent) para o padrão de uso.
 */
return new class() extends Migration {
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
