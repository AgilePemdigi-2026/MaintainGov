# Bukti Penyelesaian W10-04 — Skema Database Sprint 1
Penanggung jawab: Demas | Reviewer: Hanif | Tanggal: YYYY-MM-DD
SHA branch: <isi setelah commit> | Lingkungan: MySQL <versi>, Laravel <versi>, PHP <versi>

## 1. Perubahan artefak
- Catatan delta: docs/artifacts/shared/pdm-delta-sprint1.md

## 2. Langkah menjalankan (dapat diulang)
php artisan migrate:fresh --env=testing --seed
php artisan test --env=testing --filter=SchemaSprint1Test

## 3. Output migrate:fresh + seed
  Dropping all tables ................................................................................................................ 562.22ms DONE

   INFO  Preparing database.  

  Creating migration table ............................................................................................................ 45.58ms DONE

   INFO  Running migrations.  

  0001_01_01_000000_create_users_table ............................................................................................... 309.11ms DONE
  0001_01_01_000001_create_cache_table ............................................................................................... 163.39ms DONE
  0001_01_01_000002_create_jobs_table ................................................................................................ 227.01ms DONE
  2026_10_18_015747_create_pengguna_sistem_table ...................................................................................... 88.85ms DONE
  2026_10_18_015754_create_analysis_batch_table ...................................................................................... 241.80ms DONE
  2026_10_18_015807_create_maintenance_case_table .................................................................................... 253.27ms DONE
  2026_10_18_015815_create_ulasan_table .............................................................................................. 570.13ms DONE


   INFO  Seeding database.  

  Database\Seeders\UserAccountSeeder ....................................................................................................... RUNNING  
  Database\Seeders\UserAccountSeeder ................................................................................................... 355 ms DONE  

## 4. Hasil uji constraint
   PASS  Tests\Feature\SchemaSprint1Test
  ✓ tabel dan kolom delta ada                                                                                                                               2.97s  
  ✓ insert valid berhasil                                                                                                                                   0.09s  
  ✓ duplikat source review dalam batch ditolak                                                                                                              0.06s  
  ✓ ulasan boleh tanpa case                                                                                                                                 0.05s  
  ✓ case pending boleh tanpa validator                                                                                                                      0.07s  
  ✓ fk ulasan ke batch tidak dikenal ditolak                                                                                                                0.04s  
  ✓ rating di luar rentang ditolak                                                                                                                          0.05s  

  Tests:    7 passed (14 assertions)
  Duration: 4.06s


## 5. Keamanan
Tidak ada kredensial pribadi/konfigurasi DB asli di repo; password uji berasal dari env terdokumentasi.