<?php

declare(strict_types=1);

namespace App\Domains\AI\Support;

/**
 * Confere que o arquivo é mesmo um WAV PCM e mede a duração a partir dos FRAMES
 * reais (bytes de áudio ÷ alinhamento do bloco ÷ taxa), sem decodificar. O
 * cliente não é confiável: o teto de duração (que é o teto de custo da chamada
 * ao provedor) e o tipo do arquivo são conferidos aqui, no servidor.
 *
 * Só vale cabeçalho COERENTE: taxa × canais × bits × alinhamento × byteRate.
 * Confiar no byteRate que o arquivo declara deixava um PCM de 180 s passar como
 * 90 s (byteRate inflado), furando o teto. E o que vem depois do chunk "data"
 * também conta (vale o que há até o fim do arquivo): um decodificador que lê
 * até o fim cobraria esse áudio escondido.
 *
 * Os chunks são percorridos um a um (lê o cabeçalho de 8 bytes de cada e pula pelo
 * tamanho), então um LIST/INFO/bext grande antes do "data" não derruba um WAV
 * válido — e o áudio nunca é carregado na memória.
 */
final class WavInspector
{
    /** Até onde (bytes do início do arquivo) o cabeçalho de um chunk pode começar: o "data" tem de aparecer antes disso. */
    private const MAX_HEADER_SCAN_BYTES = 1048576; // 1 MiB

    /** O que o navegador grava é mono 16 kHz; vão além disso só valores reais de WAV PCM. */
    private const MIN_SAMPLE_RATE = 8000;

    private const MAX_SAMPLE_RATE = 48000;

    private const MAX_CHANNELS = 2;

    private const BITS = [8, 16, 24, 32];

    /**
     * Duração em segundos; null se não for um WAV PCM legível e coerente.
     */
    public static function durationSeconds(string $path): ?float
    {
        $size = @filesize($path);

        if ($size === false || $size < 44) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        try {
            return self::scan($handle, $size);
        } finally {
            fclose($handle);
        }
    }

    /** @param resource $handle */
    private static function scan($handle, int $size): ?float
    {
        $riff = (string) fread($handle, 12);

        if (strlen($riff) !== 12 || substr($riff, 0, 4) !== 'RIFF' || substr($riff, 8, 4) !== 'WAVE') {
            return null;
        }

        // Tamanho do RIFF maior que o arquivo = truncado/adulterado. 0 e 0xFFFFFFFF
        // são gravação em fluxo (tamanho ainda desconhecido).
        $riffSize = unpack('V', substr($riff, 4, 4))[1];

        if ($riffSize !== 0 && $riffSize !== 0xFFFFFFFF && $riffSize + 8 > $size) {
            return null;
        }

        $fmt    = null;
        $offset = 12;

        while ($offset + 8 <= $size && $offset <= self::MAX_HEADER_SCAN_BYTES) {
            if (fseek($handle, $offset) !== 0) {
                return null;
            }

            $header = (string) fread($handle, 8);

            if (strlen($header) !== 8) {
                return null;
            }

            $id        = substr($header, 0, 4);
            $chunkSize = unpack('V', substr($header, 4, 4))[1];

            if ($id === 'fmt ') {
                $fmt = $chunkSize >= 16 ? self::coherentFormat((string) fread($handle, 16)) : null;

                if ($fmt === null) {
                    return null;
                }
            } elseif ($id === 'data') {
                if ($fmt === null) {
                    return null; // "data" antes de "fmt ": cabeçalho inválido
                }

                return self::dataSeconds($fmt, $chunkSize, $size - ($offset + 8));
            }

            $offset += 8 + $chunkSize + ($chunkSize % 2); // chunks são alinhados em 2 bytes
        }

        return null;
    }

    /**
     * @return array{rate: int, blockAlign: int}|null
     */
    private static function coherentFormat(string $raw): ?array
    {
        if (strlen($raw) !== 16) {
            return null;
        }

        $f = unpack('vformat/vchannels/Vrate/VbyteRate/vblockAlign/vbits', $raw);

        $coherent = $f['format'] === 1 // PCM
            && $f['channels'] >= 1 && $f['channels'] <= self::MAX_CHANNELS
            && $f['rate'] >= self::MIN_SAMPLE_RATE && $f['rate'] <= self::MAX_SAMPLE_RATE
            && in_array($f['bits'], self::BITS, true)
            && $f['blockAlign'] === $f['channels'] * intdiv($f['bits'], 8)
            && $f['byteRate'] === $f['rate'] * $f['blockAlign'];

        return $coherent ? ['rate' => $f['rate'], 'blockAlign' => $f['blockAlign']] : null;
    }

    /**
     * @param array{rate: int, blockAlign: int} $fmt
     * @param int                               $declared  tamanho que o chunk "data" diz ter
     * @param int                               $available bytes que existem de fato do início do "data" até o fim do arquivo
     */
    private static function dataSeconds(array $fmt, int $declared, int $available): ?float
    {
        $streaming = $declared === 0 || $declared === 0xFFFFFFFF;

        // Declarou mais áudio do que o arquivo tem (e não é gravação em fluxo): inconsistente.
        if (! $streaming && $declared > $available) {
            return null;
        }

        // Vale o maior: o declarado ou o que há até o fim (áudio depois do chunk data).
        $bytes  = $streaming ? $available : max($declared, $available);
        $frames = intdiv($bytes, $fmt['blockAlign']);

        return $frames > 0 ? $frames / $fmt['rate'] : null;
    }
}
