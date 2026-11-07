<?php

namespace App\Services\GitHub;

use App\Exceptions\ApiException;
use App\Models\GitHubWebhookDelivery;
use App\Services\Maintenance\MaintenanceIssueService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Signed GitHub push webhook handler.
 *
 * Security order:
 * signature -> delivery id/payload -> repository identity -> event type ->
 * branch/commit policy -> GitHub API verification -> atomic status transition.
 */
class GitHubWebhookService
{
    public function __construct(
        private readonly PushVerificationService $pushVerification,
        private readonly MaintenanceIssueService $issues,
        private readonly WebhookDeliveryService $deliveries,
    ) {}

    /**
     * @return array{http_status: int, payload: array<string, mixed>}
     */
    public function handle(Request $request): array
    {
        $rawBody = $request->getContent();
        $this->verifySignature($rawBody, $request->header('X-Hub-Signature-256'));

        $event = trim((string) $request->header('X-GitHub-Event'));
        $deliveryId = trim((string) $request->header('X-GitHub-Delivery'));

        if ($event === '') {
            throw new ApiException('WEBHOOK_EVENT_REQUIRED', 'Header event webhook wajib diisi.', 400);
        }

        if ($deliveryId === '' || mb_strlen($deliveryId) > 100) {
            throw new ApiException(
                'WEBHOOK_DELIVERY_ID_INVALID',
                'Header delivery ID webhook tidak valid.',
                400,
            );
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            throw new ApiException('WEBHOOK_INVALID_PAYLOAD', 'Payload webhook tidak valid.', 400);
        }

        $repositoryFullName = data_get($payload, 'repository.full_name');
        $ref = data_get($payload, 'ref');
        $afterSha = data_get($payload, 'after');

        $context = [
            'event' => $event,
            'action' => is_string(data_get($payload, 'action')) ? data_get($payload, 'action') : null,
            'repository_full_name' => is_string($repositoryFullName) ? $repositoryFullName : null,
            'ref' => is_string($ref) ? $ref : null,
            'after_sha' => is_string($afterSha) && strlen($afterSha) === 40 ? strtolower($afterSha) : null,
        ];

        $begin = $this->deliveries->begin($deliveryId, $context);
        $delivery = $begin['delivery'];

        if ($begin['state'] === 'duplicate') {
            $this->assertSameDeliveryContext($delivery, $context);

            return [
                'http_status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Delivery webhook sudah pernah diproses.',
                    'data' => [
                        'status' => 'duplicate',
                        'delivery_id' => $deliveryId,
                        'processed_at' => $delivery->processed_at?->toIso8601String(),
                    ],
                ],
            ];
        }

        if ($begin['state'] === 'in_progress') {
            return [
                'http_status' => 202,
                'payload' => [
                    'success' => true,
                    'message' => 'Delivery webhook sedang diproses.',
                    'data' => [
                        'status' => 'in_progress',
                        'delivery_id' => $deliveryId,
                    ],
                ],
            ];
        }

        try {
            return $this->process($delivery, $deliveryId, $event, $payload, $context);
        } catch (ApiException $exception) {
            $this->deliveries->markFailed($delivery, $exception->errorCode);

            throw $exception;
        } catch (\Throwable) {
            $this->deliveries->markFailed($delivery, 'WEBHOOK_PROCESSING_FAILED');

            throw new ApiException(
                'WEBHOOK_PROCESSING_FAILED',
                'Webhook tidak dapat diproses.',
                500,
            );
        }
    }

    /**
     * A GitHub delivery id must identify exactly one payload. Reusing an id
     * with different content is a spoof/replay signal, not a duplicate.
     *
     * @param  array<string, mixed>  $context
     */
    private function assertSameDeliveryContext(
        GitHubWebhookDelivery $delivery,
        array $context,
    ): void {
        $sameRepository = $delivery->repository_full_name === null
            ? ($context['repository_full_name'] ?? null) === null
            : is_string($context['repository_full_name'] ?? null)
                && strcasecmp($delivery->repository_full_name, $context['repository_full_name']) === 0;

        $sameEvent = $delivery->event === $context['event'];
        $sameRef = $delivery->ref === ($context['ref'] ?? null);
        $sameSha = $delivery->after_sha === ($context['after_sha'] ?? null);
        $sameAction = $delivery->action === ($context['action'] ?? null);

        if (! $sameRepository || ! $sameEvent || ! $sameRef || ! $sameSha || ! $sameAction) {
            throw ApiException::conflict(
                'Delivery webhook dengan ID yang sama memiliki payload berbeda.',
                'WEBHOOK_DELIVERY_CONFLICT',
            );
        }
    }

    private function verifySignature(string $rawBody, ?string $signature): void
    {
        $secret = (string) config('services.github.webhook_secret');

        if (trim($secret) === '') {
            throw new ApiException(
                'WEBHOOK_NOT_CONFIGURED',
                'Webhook GitHub belum dikonfigurasi.',
                503,
            );
        }

        if (! is_string($signature) || $signature === '' || ! str_starts_with($signature, 'sha256=')) {
            throw new ApiException(
                'WEBHOOK_SIGNATURE_INVALID',
                'Signature webhook tidak valid.',
                401,
            );
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            throw new ApiException(
                'WEBHOOK_SIGNATURE_INVALID',
                'Signature webhook tidak valid.',
                401,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array{http_status: int, payload: array<string, mixed>}
     */
    private function process(
        GitHubWebhookDelivery $delivery,
        string $deliveryId,
        string $event,
        array $payload,
        array $context,
    ): array {
        if ($event === 'ping') {
            $this->deliveries->markProcessed($delivery);

            return [
                'http_status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Webhook GitHub terhubung.',
                    'data' => ['status' => 'pong', 'delivery_id' => $deliveryId],
                ],
            ];
        }

        if ($event !== 'push') {
            $this->deliveries->markIgnored($delivery, null, 'unsupported_event');

            return $this->ignored($deliveryId, 'unsupported_event');
        }

        $expectedRepository = trim((string) config('services.github.owner')).'/'
            .trim((string) config('services.github.repository'));

        if (! is_string($context['repository_full_name'] ?? null)
            || strcasecmp($context['repository_full_name'], $expectedRepository) !== 0) {
            throw ApiException::forbidden(
                'Repository webhook tidak sesuai dengan repository terpercaya.',
                'WEBHOOK_REPOSITORY_MISMATCH',
            );
        }

        $ref = (string) ($context['ref'] ?? '');

        if (! str_starts_with($ref, 'refs/heads/')) {
            $this->deliveries->markIgnored($delivery, null, 'unsupported_ref');

            return $this->ignored($deliveryId, 'unsupported_ref');
        }

        $branch = Str::after($ref, 'refs/heads/');
        $sha = (string) ($context['after_sha'] ?? '');

        if (! preg_match('/^[0-9a-f]{40}$/i', $sha) || $sha === str_repeat('0', 40)) {
            $this->deliveries->markIgnored($delivery, null, 'branch_deleted_or_invalid_sha');

            return $this->ignored($deliveryId, 'branch_deleted_or_invalid_sha');
        }

        $pushedAt = data_get($payload, 'head_commit.timestamp')
            ?? data_get($payload, 'repository.pushed_at');

        $this->assertFresh($pushedAt);

        $backlog = $this->issues->findBySourceBranch($branch);

        if ($backlog === null) {
            $this->deliveries->markIgnored($delivery, null, 'no_matching_issue');

            return $this->ignored($deliveryId, 'no_matching_issue');
        }

        $actorLogin = data_get($payload, 'sender.login')
            ?? data_get($payload, 'pusher.name');

        $result = $this->pushVerification->verifyWebhook(
            $backlog,
            $branch,
            strtolower($sha),
            is_string($actorLogin) ? $actorLogin : null,
            is_string($pushedAt) ? $pushedAt : null,
        );

        if ($result['outcome'] !== 'processed' || $result['issue'] === null) {
            $this->deliveries->markIgnored(
                $delivery,
                (int) $backlog->backlog_id,
                $result['reason'] ?? 'ignored',
            );

            return $this->ignored(
                $deliveryId,
                $result['reason'] ?? 'ignored',
                (int) $backlog->backlog_id,
            );
        }

        $this->deliveries->markProcessed($delivery, (int) $backlog->backlog_id);

        return [
            'http_status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'Push berhasil diverifikasi. Issue siap diajukan Pull Request.',
                'data' => [
                    'status' => 'processed',
                    'delivery_id' => $deliveryId,
                    'issue' => $result['issue'],
                ],
            ],
        ];
    }

    private function assertFresh(mixed $pushedAt): void
    {
        $maxAge = (int) config('services.github.webhook_max_age', 900);

        if ($maxAge <= 0 || ! is_string($pushedAt) || $pushedAt === '') {
            return;
        }

        try {
            $at = Carbon::parse($pushedAt);
        } catch (\Throwable) {
            throw new ApiException(
                'WEBHOOK_INVALID_TIMESTAMP',
                'Timestamp push webhook tidak valid.',
                400,
            );
        }

        if ($at->lt(now()->subSeconds($maxAge))) {
            throw ApiException::conflict(
                'Delivery webhook sudah kedaluwarsa.',
                'WEBHOOK_STALE_DELIVERY',
            );
        }
    }

    /**
     * @return array{http_status: int, payload: array<string, mixed>}
     */
    private function ignored(string $deliveryId, string $reason, ?int $backlogId = null): array
    {
        return [
            'http_status' => 202,
            'payload' => [
                'success' => true,
                'message' => 'Event webhook diterima tetapi tidak mengubah status issue.',
                'data' => [
                    'status' => 'ignored',
                    'reason' => $reason,
                    'delivery_id' => $deliveryId,
                    'issue_id' => $backlogId,
                ],
            ],
        ];
    }
}
