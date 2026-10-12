<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Only writer of audit_logs. Writers call it inside their DB transaction right after the change, so
 * the log row commits or rolls back with it. Values are raw column values (money in poisha); the
 * model's hidden columns are stored as "[redacted]".
 */
class AuditLogger
{
    public const REDACTED = '[redacted]';

    /**
     * Log a newly created row with all its columns.
     *
     * @param  array<string, mixed>  $extra  values that are not columns of the row, e.g. a user's role
     */
    public function created(string $action, Model $model, ?User $actor, array $extra = []): AuditLog
    {
        return $this->write($action, $model, $actor, null, [...$this->redact($model, $model->getAttributes()), ...$extra]);
    }

    /**
     * Log the columns changed by the model's last save, old and new values. Nothing is logged when
     * the save changed nothing and no extra values are given.
     *
     * @param  array<string, mixed>  $extraBefore
     * @param  array<string, mixed>  $extraAfter
     */
    public function updated(string $action, Model $model, ?User $actor, array $extraBefore = [], array $extraAfter = []): ?AuditLog
    {
        $after = Arr::except($model->getChanges(), [$model->getUpdatedAtColumn()]);
        if ($after === [] && $extraAfter === []) {
            return null;
        }

        $before = Arr::only($model->getPrevious(), array_keys($after));

        return $this->write(
            $action,
            $model,
            $actor,
            [...$this->redact($model, $before), ...$extraBefore],
            [...$this->redact($model, $after), ...$extraAfter],
        );
    }

    /**
     * Log a row about to be deleted with all its columns. Only for non-ledger rows (e.g. holidays).
     */
    public function deleted(string $action, Model $model, ?User $actor): AuditLog
    {
        return $this->write($action, $model, $actor, $this->redact($model, $model->getAttributes()), null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function write(string $action, Model $model, ?User $actor, ?array $before, ?array $after): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(Model $model, array $values): array
    {
        foreach (array_intersect(array_keys($values), $model->getHidden()) as $key) {
            $values[$key] = self::REDACTED;
        }

        return $values;
    }
}
