<?php

// Terjemahan pesan validasi Bahasa Indonesia (W12-02, temuan audit #2; fix CI line 187).
// Pesan HARUS menyebut field bermasalah (:attribute).
return [
    'required' => 'Kolom :attribute wajib diisi.',
    'in' => 'Nilai :attribute harus salah satu dari: :values.',
    'string' => 'Kolom :attribute harus berupa teks.',
    'integer' => 'Kolom :attribute harus berupa bilangan bulat.',
    'boolean' => 'Kolom :attribute harus bernilai benar atau salah.',
    'different' => 'Kolom :attribute harus berbeda dari :other.',
    'regex' => 'Format :attribute tidak valid.',
    'max' => [
        'string' => 'Kolom :attribute tidak boleh lebih dari :max karakter.',
        'numeric' => 'Kolom :attribute tidak boleh lebih dari :max.',
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
        'status' => 'status',
        'assigned_to_me' => 'assigned_to_me',
        'page' => 'page',
        'per_page' => 'per_page',
        'source_branch' => 'branch sumber',
        'target_branch' => 'branch tujuan',
        'title' => 'judul Pull Request',
        'description' => 'deskripsi Pull Request',
        'idempotency_key' => 'idempotency key',
        'commit_sha' => 'commit SHA',
    ],
];
