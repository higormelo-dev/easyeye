<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use InvalidArgumentException;

class EmrReport
{
    public const LABELS = ['Last Name', 'First Name', 'Patient ID', 'Exam Eye', 'Exam Date', 'Exam Time', 'Display', 'Exam Infotext', 'Date of Birth', 'Patient Comment 1', 'Patient Comment 2'];

    public function parse(string $raw): array
    {
        if (strlen($raw) > 1048576 || ! mb_check_encoding($raw, 'UTF-8')) {
            throw new InvalidArgumentException('emr_encoding_or_size_invalid');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\r(?!\n)/', $raw)) {
            throw new InvalidArgumentException('emr_control_character');
        }
        $lines = preg_split('/\r?\n/', trim($raw));

        if (count($lines) > 512) {
            throw new InvalidArgumentException('emr_line_limit');
        }
        $fields = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (! str_contains($line, ':')) {
                throw new InvalidArgumentException('emr_structure_invalid');
            }[$label,$value] = explode(':', $line, 2);
            $label           = trim($label);
            $value           = trim($value);

            if (! in_array($label, self::LABELS, true) || array_key_exists($label, $fields) || mb_strlen($value) > 512) {
                throw new InvalidArgumentException('emr_label_or_duplicate_invalid');
            }

            if (preg_match('/<\/?[A-Za-z][^>]*>/u', $value)) {
                throw new InvalidArgumentException('emr_html_invalid');
            }$fields[$label] = $value;
        }

        if (empty($fields['Patient ID']) || ! preg_match('/^[\pL\pN_. -]{1,64}$/u', $fields['Patient ID'])) {
            throw new InvalidArgumentException('emr_patient_identifier_invalid');
        }

        if (! empty($fields['Exam Eye']) && ! in_array(strtolower($fields['Exam Eye']), ['r', 'l', 'right', 'left', 'both', 'od', 'oe', 'ao'], true)) {
            throw new InvalidArgumentException('emr_eye_invalid');
        }

        foreach (['Exam Date', 'Date of Birth'] as $dateField) {
            if (! empty($fields[$dateField])) {
                $d = DateTimeImmutable::createFromFormat('!d.m.Y', $fields[$dateField]);

                if (! $d || $d->format('d.m.Y') !== $fields[$dateField]) {
                    throw new InvalidArgumentException('emr_date_invalid');
                }
            }
        }

        if (! empty($fields['Exam Time']) && ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $fields['Exam Time'])) {
            throw new InvalidArgumentException('emr_time_invalid');
        }

        return ['adapter' => 'oculus-pdm-text-v1', 'fields' => $fields];
    }

    public function svg(array $report): string
    {
        $lines = [];

        foreach ($report['fields'] as $label => $value) {
            $text = $label . ': ' . $value;

            foreach (mb_str_split($text, 80, 'UTF-8') as $line) {
                $lines[] = $line;
            }
        }
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="' . max(160, 80 + count($lines) * 25) . '" viewBox="0 0 1200 ' . max(160, 80 + count($lines) * 25) . '"><rect width="100%" height="100%" fill="white"/><g fill="black" font-family="sans-serif" font-size="20">';

        foreach ($lines as $i => $line) {
            $svg .= '<text x="25" y="' . (40 + $i * 25) . '">' . htmlspecialchars($line, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</text>';
        }

        return $svg . '</g></svg>';
    }
}
