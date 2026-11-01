<?php

// Terjemahan pesan validasi Bahasa Indonesia (W12-02, temuan audit #2; fix CI line 187).
// Pesan HARUS menyebut field bermasalah (:attribute).
return [
    'required' => 'Kolom :attribute wajib diisi.',
    'in' => 'Nilai :attribute harus salah satu dari: :values.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'max' => [
        'string' => 'Kolom :attribute tidak boleh lebih dari :max karakter.',
    ],
    'min' => [
        'numeric' => 'Kolom :attribute minimal :min.',
    ],

    // Nama field yang tampil pada pesan (:attribute)
    'attributes' => [
        'priority' => 'priority',
        'severity' => 'severity',
        'modul' => 'modul',
        'ringkasan_masalah' => 'ringkasan masalah',
    ],
];
