<?php

namespace Tests\Feature;

use Tests\TestCase;

class GitHubContractFixtureTest extends TestCase
{
    private const MOCK_FIXTURES = [
        'issues-list.json',
        'issues-empty.json',
        'issue-detail-102.json',
        'claim-success.json',
        'claim-conflict.json',
        'push-status.json',
        'branches.json',
        'pull-requests-empty.json',
        'pull-requests-open.json',
        'pull-request-create.mock.json',
    ];

    public function test_all_fixtures_are_valid_json_without_credentials(): void
    {
        $files = glob($this->fixturePath('*.json')) ?: [];

        $this->assertNotEmpty($files, 'Fixture UC04-UC05 tidak ditemukan.');

        foreach ($files as $file) {
            $content = file_get_contents($file);
            $this->assertNotFalse($content, basename($file).' tidak dapat dibaca.');

            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($data, basename($file).' harus berupa objek JSON.');

            $encoded = strtolower($content);
            $this->assertStringNotContainsString('ghp_', $encoded, basename($file).' mengandung token GitHub.');
            $this->assertStringNotContainsString('github_pat_', $encoded, basename($file).' mengandung token GitHub.');
            $this->assertStringNotContainsString('authorization', $encoded, basename($file).' mengandung header otorisasi.');

            $this->assertArrayNotHasKey('token', $data, basename($file).' mengandung field token.');
            $this->assertArrayNotHasKey('secret', $data, basename($file).' mengandung field secret.');
        }
    }

    public function test_mock_fixtures_are_explicitly_marked_as_mock(): void
    {
        foreach (self::MOCK_FIXTURES as $fixture) {
            $data = json_decode(
                file_get_contents($this->fixturePath($fixture)),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $this->assertTrue(
                ($data['meta']['is_mock'] ?? false) === true || ($data['data']['is_mock'] ?? false) === true,
                $fixture.' tidak memiliki penanda mock.',
            );
        }
    }

    public function test_connection_error_fixtures_have_safe_error_shape(): void
    {
        foreach (glob($this->fixturePath('connection-*.json')) ?: [] as $file) {
            if (str_ends_with($file, 'connection-success.json')) {
                continue;
            }

            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertFalse($data['success'], basename($file).' harus success=false.');
            $this->assertArrayHasKey('error', $data, basename($file).' harus memiliki error.');
            $this->assertArrayHasKey('code', $data['error'], basename($file).' harus memiliki error.code.');
            $this->assertArrayHasKey('message', $data['error'], basename($file).' harus memiliki error.message.');
        }
    }

    private function fixturePath(string $file): string
    {
        return base_path('../../contracts/fixtures/uc04-uc05/'.$file);
    }
}
