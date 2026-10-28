<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inclui no catálogo CID-10 os códigos do capítulo Z de uso rotineiro em
 * oftalmologia (Z01.0 "Exame dos olhos e da visão" era o mais pedido). O
 * Cid10CodesSeeder só roda em instalação nova, então bancos já semeados não
 * recebiam códigos acrescentados depois — esta migration cobre esses bancos.
 * Idempotente: não duplica nem sobrescreve código já existente.
 */
return new class() extends Migration {
    private const CODES = [
        'Z01.0' => 'Exame dos olhos e da visão',
        'Z13.5' => 'Exame especial de rastreamento de doenças dos olhos e dos ouvidos',
        'Z46.0' => 'Colocação e ajustamento de óculos e lentes de contato',
        'Z83.5' => 'História familiar de transtornos dos olhos e dos ouvidos',
        'Z94.7' => 'Córnea transplantada',
        'Z96.1' => 'Presença de lente intra-ocular',
        'Z97.3' => 'Presença de óculos e de lentes de contato',
    ];

    public function up(): void
    {
        $now = now();

        DB::table('cid10_codes')->insertOrIgnore(
            collect(self::CODES)->map(fn (string $description, string $code) => [
                'id'          => (string) Str::uuid(),
                'code'        => $code,
                'description' => $description,
                'category'    => 'Exames e Acompanhamento',
                'created_at'  => $now,
                'updated_at'  => $now,
            ])->values()->all(),
        );
    }

    public function down(): void
    {
        // Não remove: códigos podem já estar referenciados em prontuários/exames.
    }
};
