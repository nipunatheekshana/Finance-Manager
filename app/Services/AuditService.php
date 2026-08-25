<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records the deliberate changes a user makes to their plan.
 *
 * Only the fields that matter to the audit trail are stored — never a full
 * dump of a financial record — so the log stays useful without becoming a
 * second copy of the user's finances.
 */
class AuditService
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        int $userId,
        string $action,
        Model $subject,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $note = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'old_values' => $this->stringify($oldValues),
            'new_values' => $this->stringify($newValues),
            'note' => $note,
        ]);
    }

    /**
     * Capture only the attributes that actually changed on a model.
     *
     * @param  list<string>  $watch
     */
    public function recordChanges(int $userId, string $action, Model $subject, array $watch, ?string $note = null): ?AuditLog
    {
        $changed = array_intersect_key($subject->getDirty(), array_flip($watch));

        if ($changed === []) {
            return null;
        }

        $original = [];
        foreach (array_keys($changed) as $key) {
            $original[$key] = $subject->getOriginal($key);
        }

        return $this->record($userId, $action, $subject, $original, $changed, $note);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function stringify(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_map(
            fn (mixed $value) => is_scalar($value) || $value === null ? $value : (string) $value,
            $values
        );
    }
}
