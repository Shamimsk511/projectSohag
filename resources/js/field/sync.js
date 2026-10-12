/**
 * Sends the offline queue to /api/field/collections/sync (protocol in docs/PWA.md).
 * Only runs from live signed-in pages: it needs the session cookie and the page's CSRF token.
 */
import { putQueued, queuedFor, removeQueued } from './db.js';

const ENDPOINT = '/api/field/collections/sync';
const BATCH_SIZE = 50;
const KEEP_SYNCED_MS = 7 * 24 * 60 * 60 * 1000;

/** Drops synced items older than a week (pending and rejected items are never pruned). */
export async function pruneSynced(userId) {
    const cutoff = Date.now() - KEEP_SYNCED_MS;
    const old = (await queuedFor(userId)).filter((item) => item.state === 'synced' && item.updated_at < cutoff);
    if (old.length) {
        await removeQueued(old.map((item) => item.client_uuid));
    }
}

const failedAttempt = (items, error) => putQueued(items.map((item) => ({
    ...item, attempts: item.attempts + 1, last_error: error, updated_at: Date.now(),
})));

function applyResult(item, result) {
    const updatedAt = Date.now();
    if (!result || result.status === 'error') {
        return { ...item, attempts: item.attempts + 1, last_error: 'error', updated_at: updatedAt };
    }
    if (result.status === 'rejected') {
        return {
            ...item,
            state: 'rejected',
            rejection: { code: result.code, message: result.message, errors: result.errors ?? {} },
            updated_at: updatedAt,
        };
    }
    return {
        ...item,
        state: 'synced',
        last_error: null,
        result: {
            transaction_id: result.transaction_id,
            receipt_no: result.receipt_no,
            receipt_url: result.receipt_url,
            amount_label: result.amount_label,
        },
        updated_at: updatedAt,
    };
}

/**
 * Posts the user's pending items oldest first, in batches. Resolves with
 * { status: ok|offline|failed|unauthenticated|stale_token, results: Map<client_uuid, server result> }.
 */
export function syncQueue(userId, csrfToken) {
    const run = async () => {
        const results = new Map();
        for (;;) {
            const batch = (await queuedFor(userId))
                .filter((item) => item.state === 'pending')
                .sort((a, b) => a.collected_at.localeCompare(b.collected_at))
                .slice(0, BATCH_SIZE);
            if (batch.length === 0) {
                return { status: 'ok', results };
            }

            let response;
            try {
                response = await fetch(ENDPOINT, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({
                        items: batch.map(({ client_uuid, schedule_id, amount, payment_method, description, collected_at }) => ({
                            client_uuid, schedule_id, amount, payment_method, description, collected_at,
                        })),
                    }),
                });
            } catch {
                await failedAttempt(batch, 'network');
                return { status: 'offline', results };
            }

            if (response.status === 401) {
                return { status: 'unauthenticated', results };
            }
            if (response.status === 419) {
                return { status: 'stale_token', results };
            }
            if (!response.ok) {
                await failedAttempt(batch, `http_${response.status}`);
                return { status: 'failed', results };
            }

            const byUuid = new Map((await response.json()).results.map((result) => [result.client_uuid, result]));
            byUuid.forEach((result, clientUuid) => results.set(clientUuid, result));
            await putQueued(batch.map((item) => applyResult(item, byUuid.get(item.client_uuid))));

            // Only server errors left in this batch: stop and retry later instead of looping.
            if (batch.every((item) => (byUuid.get(item.client_uuid)?.status ?? 'error') === 'error')) {
                return { status: 'failed', results };
            }
        }
    };

    // One run per browser; a second tab waits. Double runs would still be safe (client_uuid).
    return navigator.locks ? navigator.locks.request('field-sync', run) : run();
}
