<?php

declare(strict_types=1);

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\When;
use Behat\Step\Then;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;

/**
 * Sprint 1 HTTP acceptance tests against an isolated, seeded MaintainGov stack.
 * This context intentionally does not reset a database or stop a shared service.
 */
final class FeatureContext implements Context
{
    private Client $client;
    private CookieJar $cookies;
    private ?ResponseInterface $lastResponse = null;
    private ?int $batchId = null;
    private ?string $lastCsv = null;
    private ?string $lastKey = null;
    private array $savedIds = [];
    private array $batchData = [];

    private function baseUrl(): string
    {
        return rtrim(getenv('MAINTAINGOV_API_URL') ?: 'http://127.0.0.1:8000/api/v1', '/') . '/';
    }

    private function httpClient(CookieJar $jar): Client
    {
        return new Client([
            'base_uri' => $this->baseUrl(),
            'cookies' => $jar,
            'http_errors' => false,
            'timeout' => 150,
            'connect_timeout' => 8,
        ]);
    }

    private function requireClient(): void
    {
        if (!isset($this->client)) {
            throw new RuntimeException('Start the scenario with a fresh API session.');
        }
    }

    private function csrfToken(): string
    {
        $response = $this->client->get('auth/csrf', [
            'headers' => ['Accept' => 'application/json', 'Origin' => 'http://127.0.0.1:3000'],
        ]);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException('CSRF bootstrap failed: HTTP ' . $response->getStatusCode());
        }
        $token = json_decode((string) $response->getBody(), true)['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new RuntimeException('The CSRF endpoint returned no session token.');
        }
        return $token;
    }

    private function send(string $method, string $path, ?array $json = null): void
    {
        $this->requireClient();
        $options = [
            'headers' => ['Accept' => 'application/json', 'Origin' => 'http://127.0.0.1:3000'],
        ];
        if ($json !== null) {
            $options['headers']['X-CSRF-TOKEN'] = $this->csrfToken();
            $options['json'] = $json;
        }
        $this->lastResponse = $this->client->request($method, $path, $options);
    }

    private function responseJson(): array
    {
        if ($this->lastResponse === null) {
            throw new RuntimeException('No HTTP response available.');
        }
        $decoded = json_decode((string) $this->lastResponse->getBody(), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected JSON; received: ' . substr((string) $this->lastResponse->getBody(), 0, 500));
        }
        return $decoded;
    }

    private function requireStatus(int $expected): void
    {
        $actual = $this->lastResponse?->getStatusCode() ?? 0;
        if ($actual !== $expected) {
            $body = $this->lastResponse ? substr((string) $this->lastResponse->getBody(), 0, 800) : 'No response';
            throw new RuntimeException(sprintf('Expected HTTP %d, received %d. %s', $expected, $actual, $body));
        }
    }

    private function upload(string $csv, ?string $key = null): void
    {
        $this->requireClient();
        $key ??= bin2hex(random_bytes(16));
        $this->lastKey = $key;
        $this->lastCsv = $csv;
        $this->lastResponse = $this->client->post('analysis-batches', [
            'headers' => [
                'Accept' => 'application/json',
                'Origin' => 'http://127.0.0.1:3000',
                'X-CSRF-TOKEN' => $this->csrfToken(),
                'Idempotency-Key' => $key,
            ],
            'multipart' => [[
                'name' => 'file',
                'contents' => $csv,
                'filename' => 'bdd-reviews.csv',
                'headers' => ['Content-Type' => 'text/csv'],
            ]],
        ]);
        if ($this->lastResponse->getStatusCode() === 202) {
            $this->batchId = (int) ($this->responseJson()['data']['batch_id'] ?? 0);
            if ($this->batchId <= 0) {
                throw new RuntimeException('Batch accepted without batch_id.');
            }
        }
    }

    #[Given('a fresh unauthenticated API session')]
    public function freshSession(): void
    {
        $this->cookies = new CookieJar();
        $this->client = $this->httpClient($this->cookies);
        $this->send('GET', 'auth/me');
        $this->requireStatus(401);
    }

    #[When('I log in as :role')]
    public function login(string $role): void
    {
        $email = match ($role) {
            'maintenance' => 'maintenance@maintaingov.test',
            'developer' => 'developer@maintaingov.test',
            default => throw new InvalidArgumentException('Unknown role: ' . $role),
        };
        $this->send('POST', 'auth/login', [
            'email' => $email,
            'password' => getenv('MAINTAINGOV_DEMO_PASSWORD') ?: 'password123',
        ]);
        $this->requireStatus(200);
    }

    #[When('I log in with an invalid password')]
    public function invalidPassword(): void
    {
        $this->send('POST', 'auth/login', [
            'email' => 'maintenance@maintaingov.test',
            'password' => 'this-is-not-the-password',
        ]);
    }

    #[Then('the HTTP response is :status')]
    public function httpStatus(string $status): void
    {
        $this->requireStatus((int) $status);
    }

    #[Then('my API role is :role')]
    public function role(string $role): void
    {
        $this->send('GET', 'auth/me');
        $this->requireStatus(200);
        $actual = $this->responseJson()['data']['role'] ?? null;
        if ($actual !== $role) {
            throw new RuntimeException(sprintf('Expected role %s, got %s', $role, var_export($actual, true)));
        }
    }

    #[Then('the login error does not reveal which credential was wrong')]
    public function genericLoginError(): void
    {
        $this->requireStatus(401);
        if (($this->responseJson()['message'] ?? null) !== 'Email atau password salah.') {
            throw new RuntimeException('Login failure message differs from the agreed API contract.');
        }
    }

    #[When('I log out and replay the old session cookie')]
    public function logoutOldCookie(): void
    {
        $this->requireClient();
        $stolenCookies = clone $this->cookies;
        $this->send('POST', 'auth/logout', []);
        $this->requireStatus(200);
        $stolenClient = $this->httpClient($stolenCookies);
        $this->lastResponse = $stolenClient->get('auth/me', ['headers' => ['Accept' => 'application/json']]);
    }

    #[When('I request the protected analysis endpoint')]
    public function requestProtectedAnalysis(): void
    {
        $this->send('GET', 'analysis-batches/999999/status');
    }

    #[When('I submit a CSV with missing required columns')]
    public function badCsv(): void
    {
        $this->upload("wrong,header\none,two\n");
    }

    #[When('I submit the valid feedback fixture')]
    public function validCsv(): void
    {
        $path = dirname(__DIR__, 4) . '/services/analysis/examples/reviews.csv';
        $csv = @file_get_contents($path);
        if ($csv === false) {
            throw new RuntimeException('Missing fixture: ' . $path);
        }
        $this->upload($csv);
    }

    #[Then('the batch eventually becomes :status')]
    public function batchEventually(string $status): void
    {
        $this->requireStatus(202);
        if ($this->batchId === null) {
            throw new RuntimeException('No accepted batch to poll.');
        }
        for ($n = 0; $n < 60; $n++) {
            $this->send('GET', 'analysis-batches/' . $this->batchId);
            $this->requireStatus(200);
            $this->batchData = $this->responseJson()['data'] ?? [];
            if (($this->batchData['status'] ?? null) !== 'Diproses') {
                if (($this->batchData['status'] ?? null) !== $status) {
                    throw new RuntimeException('Unexpected batch state: ' . json_encode($this->batchData));
                }
                return;
            }
            sleep(1);
        }
        throw new RuntimeException('Batch did not finish after 60 polling attempts. Is queue/model running?');
    }

    #[Then('Pending cases are linked to their source reviews')]
    public function linkedCases(): void
    {
        $cases = $this->batchData['cases'] ?? [];
        $reviews = $this->batchData['reviews'] ?? [];
        if (!$cases || !$reviews) {
            throw new RuntimeException('No persisted cases/reviews found: ' . json_encode($this->batchData));
        }
        $caseIds = array_map(static fn(array $c) => $c['case_id'], $cases);
        foreach ($cases as $case) {
            if (($case['status_validasi'] ?? null) !== 'Pending') {
                throw new RuntimeException('New case must remain Pending until validated.');
            }
            if (!array_filter($reviews, static fn(array $r) => ($r['case_id'] ?? null) === $case['case_id'])) {
                throw new RuntimeException('Case has no linked source reviews.');
            }
        }
        foreach ($reviews as $review) {
            if (!array_key_exists('analysis_result', $review) || $review['analysis_result'] === null) {
                throw new RuntimeException('Review analysis result is missing.');
            }
            if (($review['case_id'] ?? null) !== null && !in_array($review['case_id'], $caseIds, false)) {
                throw new RuntimeException('Orphan review/case relationship.');
            }
        }
        $this->savedIds = [
            'reviews' => array_column($reviews, 'review_id'),
            'cases' => array_column($cases, 'case_id'),
        ];
    }

    #[When('I upload the same CSV with the same idempotency key')]
    public function uploadAgain(): void
    {
        if ($this->lastCsv === null || $this->lastKey === null || $this->batchId === null) {
            throw new RuntimeException('No preceding valid batch available.');
        }
        $oldBatchId = $this->batchId;
        $this->upload($this->lastCsv, $this->lastKey);
        $this->requireStatus(202);
        if ($this->batchId !== $oldBatchId) {
            throw new RuntimeException('Duplicate upload created a second batch.');
        }
    }

    #[Then('the persisted review and case identifiers remain unchanged')]
    public function persistentIds(): void
    {
        $this->send('GET', 'analysis-batches/' . $this->batchId);
        $this->requireStatus(200);
        $data = $this->responseJson()['data'];
        $current = [
            'reviews' => array_column($data['reviews'] ?? [], 'review_id'),
            'cases' => array_column($data['cases'] ?? [], 'case_id'),
        ];
        if ($current !== $this->savedIds) {
            throw new RuntimeException('Idempotent replay changed persisted identifiers.');
        }
    }
}
