<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class FeedbackCsv
{
    public function parse(string $path): array
    {
        $raw = file_get_contents($path);
        $fail = fn (string $message) => throw ValidationException::withMessages(['file' => $message]);
        if (!$raw || !mb_check_encoding($raw, 'UTF-8')) {
            $fail('CSV kosong atau encoding bukan UTF-8.');
        }
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $raw));
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, ',', '"', '');
            $required = ['reviewId', 'Review Teks', 'rating', 'date'];
            if (!$header || count($header) !== count(array_unique($header)) || array_diff($required, $header)) {
                $fail('Kolom wajib: reviewId, Review Teks, rating, date; nama kolom tidak boleh ganda.');
            }
            $rows = [];
            $seen = [];
            while (($values = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($header)) {
                    $fail('Jumlah kolom pada salah satu baris tidak sesuai header.');
                }
                $row = array_map(fn ($v) => trim($v ?? ''), array_combine($header, $values));
                $id = $row['reviewId'];
                $text = $row['Review Teks'];
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $row['date']);
                if ($id === '' || mb_strlen($id) > 100 || isset($seen[$id]) || $text === '' || mb_strlen($text) > 10000
                    || !in_array($row['rating'], ['1', '2', '3', '4', '5'], true)
                    || !$date || $date->format('Y-m-d H:i:s') !== $row['date']) {
                    $fail('Baris tidak valid: periksa ID unik, teks, rating 1–5 dan tanggal YYYY-MM-DD HH:MM:SS.');
                }
                $seen[$id] = true;
                $rows[] = ['source_review_id' => $id, 'teks_ulasan' => $text,
                    'rating' => (int) $row['rating'], 'tanggal_ulasan' => $row['date'], 'sumber' => 'csv'];
                if (count($rows) > 10000) {
                    $fail('Maksimum 10.000 ulasan per unggahan.');
                }
            }
            if (!$rows) {
                $fail('CSV tidak berisi ulasan.');
            }
            return $rows;
        } finally {
            fclose($stream);
        }
    }
}
