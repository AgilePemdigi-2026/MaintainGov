<?php

namespace App\Services\GitHub;

use App\Models\GitHubWebhookDelivery;
use Illuminate\Database\QueryException;

/**
 * Persistent duplicate/replay guard for GitHub webhook deliveries.
 */
class WebhookDeliveryService
{
    private const RECEIVED_RETRY_TTL_SECONDS = 300;

    /**
     * @param  array{event: string, action?: string|null, repository_full_name?: string|null, ref?: string|null, after_sha?: string|null}  $context
     * @return array{delivery: GitHubWebhookDelivery, state: string}
     */
    public function begin(string $deliveryId, array $context): array
    {
        try {
            $delivery = GitHubWebhookDelivery::create([
                'github_delivery_id' => $deliveryId,
                'event' => $context['event'],
                'action' => $context['action'] ?? null,
                'repository_full_name' => $context['repository_full_name'] ?? null,
                'ref' => $context['ref'] ?? null,
                'after_sha' => $context['after_sha'] ?? null,
                'status' => 'received',
            ]);

            return ['delivery' => $delivery, 'state' => 'new'];
        } catch (QueryException) {
            // Unique delivery id: another request already recorded it.
        }

        $delivery = GitHubWebhookDelivery::query()
            ->where('github_delivery_id', $deliveryId)
            ->first();

        if ($delivery === null) {
            // A database error unrelated to the unique constraint.
            return [
                'delivery' => GitHubWebhookDelivery::create([
                    'github_delivery_id' => $deliveryId,
                    'event' => $context['event'],
                    'status' => 'received',
                ]),
                'state' => 'new',
            ];
        }

        if (in_array($delivery->status, ['processed', 'ignored'], true)) {
            return ['delivery' => $delivery, 'state' => 'duplicate'];
        }

        if ($delivery->status === 'failed') {
            $delivery->forceFill([
                'status' => 'received',
                'error_code' => null,
                'processed_at' => null,
            ])->save();

            return ['delivery' => $delivery->refresh(), 'state' => 'retry'];
        }

        // Concurrent request is still processing this delivery. Stale
        // "received" rows (e.g. worker crash) may be retried after a TTL.
        if ($delivery->updated_at !== null
            && $delivery->updated_at->gt(now()->subSeconds(self::RECEIVED_RETRY_TTL_SECONDS))) {
            return ['delivery' => $delivery, 'state' => 'in_progress'];
        }

        $delivery->forceFill(['updated_at' => now()])->save();

        return ['delivery' => $delivery->refresh(), 'state' => 'retry'];
    }

    public function markProcessed(GitHubWebhookDelivery $delivery, ?int $backlogId = null): GitHubWebhookDelivery
    {
        $delivery->forceFill([
            'status' => 'processed',
            'backlog_id' => $backlogId,
            'error_code' => null,
            'processed_at' => now(),
        ])->save();

        return $delivery->refresh();
    }

    public function markIgnored(
        GitHubWebhookDelivery $delivery,
        ?int $backlogId = null,
        ?string $reason = null,
    ): GitHubWebhookDelivery {
        $delivery->forceFill([
            'status' => 'ignored',
            'backlog_id' => $backlogId,
            'error_code' => $reason,
            'processed_at' => now(),
        ])->save();

        return $delivery->refresh();
    }

    public function markFailed(GitHubWebhookDelivery $delivery, string $errorCode): GitHubWebhookDelivery
    {
        $delivery->forceFill([
            'status' => 'failed',
            'error_code' => $errorCode,
            'processed_at' => null,
        ])->save();

        return $delivery->refresh();
    }
}
