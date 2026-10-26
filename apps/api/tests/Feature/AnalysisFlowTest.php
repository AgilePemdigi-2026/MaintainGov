<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeFeedback;
use App\Models\Pengguna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalysisFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_file_upload_is_validation_error_without_creating_batch(): void
    {
        $this->actingAs($this->user())->postJson('/api/v1/analysis-batches', ['file' => 'not-an-upload'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('analysis_batch', 0);
    }

    public function test_upload_above_decimal_ten_megabytes_is_rejected(): void
    {
        $file = $this->csv()->size(9766);
        $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertDatabaseCount('analysis_batch', 0);
    }

    private function user(string $role = 'Tim Maintenance'): Pengguna
    {
        return Pengguna::create(['nama' => 'Penguji', 'email' => uniqid().'@test.local',
            'password' => 'password123', 'role' => $role]);
    }

    private function csv(string $content = ''): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('reviews.csv', $content ?: "reviewId,Review Teks,rating,date\nr-1,tidak bisa login,1,2026-10-19 08:00:00\n");
    }

    private function response(int $batch, int $review): array
    {
        return ['schema_version' => '1.0', 'analysis_version' => 'integration-test', 'batch_id' => $batch,
            'results' => [['review_id' => $review, 'kategori' => 'defect', 'sentimen' => 'negative']],
            'cases' => [['group_key' => 'login', 'member_review_ids' => [$review], 'judul' => 'Login gagal',
                'ringkasan' => 'Gagal masuk', 'category' => 'defect', 'severity' => 'high',
                'module' => 'auth', 'feature' => 'login', 'priority_score' => 7.3, 'recommendation' => 'Periksa login']]];
    }

    public function test_upload_persists_results_and_case_and_replay_does_not_duplicate(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(fn ($request) => Http::response($this->response($request['batch_id'], $request['reviews'][0]['review_id'])));
        $user = $this->user();
        $first = $this->actingAs($user)->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json']);
        $first->assertStatus(202)->assertJsonPath('data.status', 'Selesai');
        $id = $first->json('data.batch_id');
        $this->getJson('/api/v1/analysis-batches/'.$id)->assertOk()
            ->assertJsonPath('data.cases.0.status_validasi', 'Pending')
            ->assertJsonPath('data.reviews.0.source_review_id', 'r-1')
            ->assertJsonPath('data.reviews.0.analysis_result.sentimen', 'negative');
        $this->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('data.batch_id', $id);
        (new AnalyzeFeedback($id))->handle();
        $this->assertDatabaseCount('analysis_batch', 1);
        $this->assertDatabaseCount('maintenance_case', 1);
        $this->assertDatabaseCount('ulasan', 1);
        Http::assertSentCount(1);
    }

    public function test_service_failure_then_retry_recovers_without_partial_cases(): void
    {
        config(['queue.default' => 'sync']);
        $fail = true;
        Http::fake(function ($r) use (&$fail) {
            return $fail ? Http::response([], 503) : Http::response($this->response($r['batch_id'], $r['reviews'][0]['review_id']));
        });
        $result = $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json']);
        $result->assertStatus(202)->assertJsonPath('data.status', 'Gagal');
        $id = $result->json('data.batch_id');
        $this->assertDatabaseCount('maintenance_case', 0);
        $fail = false;
        $this->postJson('/api/v1/analysis-batches/'.$id.'/retry')->assertStatus(202)->assertJsonPath('data.status', 'Selesai');
        $this->postJson('/api/v1/analysis-batches/'.$id.'/retry')->assertStatus(409);
        $this->assertDatabaseCount('maintenance_case', 1);
        $this->assertDatabaseCount('ulasan', 1);
    }

    public function test_malformed_service_response_never_marks_success(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(fn ($r) => Http::response($this->response($r['batch_id'], 999999)));
        $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('data.status', 'Gagal');
        $this->assertDatabaseCount('maintenance_case', 0);
        $this->assertNull(DB::table('ulasan')->first()->analysis_result);
    }

    public function test_invalid_csv_is_rejected_before_calling_service(): void
    {
        Http::fake();
        $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv("x,y\na,b\n")], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertDatabaseCount('analysis_batch', 0);
        Http::assertNothingSent();
    }

    public function test_csv_quotes_comma_and_newline_are_preserved(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(fn ($r) => Http::response($this->response($r['batch_id'], $r['reviews'][0]['review_id'])));
        $file = $this->csv("reviewId,Review Teks,rating,date\nr-1,\"Tidak bisa login, muncul \"\"error\"\"\nsetelah update\",1,2026-10-19 08:00:00\n");
        $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(202);
        $this->assertSame("Tidak bisa login, muncul \"error\"\nsetelah update", DB::table('ulasan')->first()->teks_ulasan);
    }

    public function test_developer_and_anonymous_cannot_upload(): void
    {
        $this->postJson('/api/v1/analysis-batches')->assertUnauthorized();
        $this->actingAs($this->user('Developer'))->postJson('/api/v1/analysis-batches')->assertForbidden();
    }

    public function test_batch_is_not_readable_by_another_user(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(['*' => Http::response([], 503)]);
        $id = $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json'])->json('data.batch_id');
        $this->actingAs($this->user())->getJson('/api/v1/analysis-batches/'.$id)->assertNotFound();
    }

    public function test_same_request_key_with_different_file_is_conflict(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(['*' => Http::response([], 503)]);
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'one-request'];
        $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv()], $headers)->assertStatus(202);
        $this->post('/api/v1/analysis-batches', ['file' => $this->csv("reviewId,Review Teks,rating,date\nr-2,aplikasi lambat,2,2026-10-19 08:00:00\n")], $headers)->assertStatus(409);
        $this->assertDatabaseCount('analysis_batch', 1);
    }

    public function test_database_queue_records_one_job_for_repeated_upload(): void
    {
        config(['queue.default' => 'database']);
        Http::fake();
        $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('data.status', 'Diproses');
        $this->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json'])->assertStatus(202);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('analysis_batch', 1);
        Http::assertNothingSent();
    }

    public function test_no_actionable_case_still_persists_review_result(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(function ($r) {
            $data = $this->response($r['batch_id'], $r['reviews'][0]['review_id']);
            $data['cases'] = [];

            return Http::response($data);
        });
        $id = $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json'])->json('data.batch_id');
        $this->getJson('/api/v1/analysis-batches/'.$id)->assertOk()->assertJsonPath('data.status', 'Selesai')
            ->assertJsonCount(0, 'data.cases')->assertJsonPath('data.reviews.0.case_id', null);
        $this->assertNotNull(DB::table('ulasan')->first()->analysis_result);
    }

    public function test_unknown_case_member_rolls_back_all_outputs(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(function ($r) {
            $data = $this->response($r['batch_id'], $r['reviews'][0]['review_id']);
            $data['cases'][0]['member_review_ids'] = [999999];

            return Http::response($data);
        });
        $this->actingAs($this->user())->post('/api/v1/analysis-batches', ['file' => $this->csv()], ['Accept' => 'application/json'])
            ->assertStatus(202)->assertJsonPath('data.status', 'Gagal');
        $this->assertDatabaseCount('maintenance_case', 0);
        $this->assertNull(DB::table('ulasan')->first()->analysis_result);
    }

    public function test_file_tepat_10_mb_diterima(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(['*' => Http::response($this->response(1, 1))]);

        $header = "reviewId,Review Teks,rating,date\n";
        $maxRows = 10_000;
        $target = 10_000_000;

        $textLength = 970;

        $rows = [];
        for ($i = 1; $i <= $maxRows; $i++) {
            $rows[] = "r-{$i},".str_repeat('a', $textLength).",3,2026-10-19 08:00:00\n";
        }

        $content = $header.implode('', $rows);

        $this->assertLessThanOrEqual($target, strlen($content), 'File harus <= 10 MB');
        $this->assertSame($maxRows, count($rows), 'Jumlah baris harus tepat 10.000');

        $file = UploadedFile::fake()->createWithContent('big.csv', $content);
        $this->actingAs($this->user())
            ->post('/api/v1/analysis-batches', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(202);
    }

    public function test_file_lebih_dari_10_mb_ditolak(): void
    {
        $content = str_repeat('x', 10_000_001);
        $file = UploadedFile::fake()->createWithContent('toobig.csv', $content);

        $this->actingAs($this->user())
            ->post('/api/v1/analysis-batches', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('errors.file.0', 'Ukuran file melebihi batas 10.000.000 byte.');
    }

    public function test_csv_lebih_dari_10000_baris_ditolak(): void
    {
        $rows = ['reviewId,Review Teks,rating,date'];
        for ($i = 1; $i <= 10001; $i++) {
            $rows[] = "r-$i,teks ulasan $i,3,2026-10-19 08:00:00";
        }
        $file = UploadedFile::fake()->createWithContent('many.csv', implode("\n", $rows));

        $this->actingAs($this->user())
            ->post('/api/v1/analysis-batches', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertDatabaseCount('analysis_batch', 0);
    }

    public function test_csv_tepat_10000_baris_diterima(): void
    {
        config(['queue.default' => 'sync']);
        Http::fake(['*' => Http::response([
            'schema_version' => '1.0',
            'analysis_version' => 'test',
            'batch_id' => 1,
            'results' => [],
            'cases' => [],
        ])]);

        $rows = ['reviewId,Review Teks,rating,date'];
        for ($i = 1; $i <= 10000; $i++) {
            $rows[] = "r-$i,teks ulasan $i,3,2026-10-19 08:00:00";
        }
        $file = UploadedFile::fake()->createWithContent('ok10k.csv', implode("\n", $rows));

        $this->actingAs($this->user())
            ->post('/api/v1/analysis-batches', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(202);
    }
}
