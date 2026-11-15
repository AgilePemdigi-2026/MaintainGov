"use client";
// Komponen tampilan UC06 (review Pull Request) dan Status/Riwayat.
import { DECISION_TONE, SYNC_LABEL, reviewBadge } from "@/lib/sprint3/labels";
import type { PullRequestSummary, SyncStatus } from "@/lib/sprint3/types";

export function ReviewStatusPill({ pr }: { pr: Pick<PullRequestSummary, "review_status" | "ready_for_merge" | "status_label"> }) {
  const badge = reviewBadge(pr);
  return <span className={`ws-pill ws-pill--${badge.tone}`}>{badge.label}</span>;
}

export function DecisionPill({ decision }: { decision: string }) {
  return <span className={`ws-pill ws-pill--${DECISION_TONE[decision] ?? "muted"}`}>{decision}</span>;
}

export function SyncPill({ status }: { status: SyncStatus | string }) {
  const meta = SYNC_LABEL[status as SyncStatus] ?? { label: status, tone: "muted" };
  return <span className={`ws-pill ws-pill--${meta.tone}`}>{meta.label}</span>;
}
