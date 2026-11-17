<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\Contracts\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Records updates and deletes of audited models (T2-8): MenuItem and ModifierOption, via
 * #[ObservedBy]. Creates aren't recorded (the plan asks for update / delete).
 *
 * Only Eloquent saves and deletes fire observers, so these models must never be changed
 * with a bulk query (Model::query()->update() / ->delete()). Filament's toggles, edit pages,
 * and bulk delete all go through the model. Deleting a modifier group deletes its options
 * through Eloquent first (ModifierGroup::delete()), so they are recorded too.
 */
final class AuditObserver
{
    /** Bookkeeping columns that change on every save: not worth an entry on their own. */
    private const IGNORED = ['created_at', 'updated_at'];

    /**
     * Runs after the UPDATE, before the model syncs its original values, so getOriginal()
     * still holds what the row was.
     */
    public function updated(Model&Auditable $model): void
    {
        $changed = array_values(array_diff(array_keys($model->getChanges()), self::IGNORED));

        if ($changed === []) {
            return;
        }

        $old = [];
        $new = [];

        foreach ($changed as $key) {
            $old[$key] = $model->getOriginal($key);
            $new[$key] = $model->getAttribute($key);
        }

        $this->record($model, AuditLog::UPDATED, $old, $new);
    }

    public function deleted(Model&Auditable $model): void
    {
        $this->record($model, AuditLog::DELETED, Arr::except($model->attributesToArray(), self::IGNORED), null);
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function record(Model&Auditable $model, string $event, ?array $old, ?array $new): void
    {
        $user = auth()->user();

        AuditLog::query()->create([
            'user_id' => $user instanceof User ? $user->id : null,
            'event' => $event,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'auditable_label' => $model->auditLabel(),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
