<?php

namespace App\Services;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ModifierGroupService
{
    public function __construct(
        protected readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Writes the group and syncs its options in one transaction: sent ids are updated, rows without an id created,
     * the rest soft deleted. The array order is the display order.
     *
     * @param  array<string, mixed>  $data  validated by SaveModifierGroupRequest
     */
    public function save(?ModifierGroup $group, array $data): ModifierGroup
    {
        return DB::transaction(function () use ($group, $data) {
            // Locked so two managers saving at once run one after the other: the last save wins, both are audited.
            $group = $group ? ModifierGroup::lockForUpdate()->findOrFail($group->getKey()) : new ModifierGroup;
            $before = $group->exists ? $group->snapshot() : null;
            $group->fill(Arr::only($data, ['name', 'is_required', 'min_select', 'max_select']))->save();

            $existing = $group->options()->get()->keyBy('id');
            $kept = [];

            foreach (array_values($data['options']) as $sort => $row) {
                $option = $existing->get($row['id'] ?? 0) ?? $group->options()->make();
                $option->fill([...Arr::only($row, ['name', 'price_delta', 'is_default']), 'sort' => $sort])->save();
                $option->components()->sync(collect($row['components'] ?? [])
                    ->mapWithKeys(fn (array $line) => [$line['product_id'] => ['qty' => $line['qty']]])
                    ->all());
                $option->disabledOutlets()->sync($row['disabled_outlet_ids'] ?? []);
                $kept[] = $option->getKey();
            }

            $existing->except($kept)->each($this->deleteOption(...));

            $this->auditLogService->log(
                event: $before ? 'modifier_group.updated' : 'modifier_group.created',
                module: 'modifiers',
                auditable: $group,
                description: $before ? 'Grup modifier diubah.' : 'Grup modifier ditambahkan.',
                before: $before,
                after: ModifierGroup::findOrFail($group->getKey())->snapshot()
            );

            // The relations loaded for the "before" snapshot are stale now.
            return $group->unsetRelations();
        });
    }

    /** Soft delete with its options, and off every menu; past sales are untouched. */
    public function delete(ModifierGroup $group): void
    {
        DB::transaction(function () use ($group) {
            // Re-read under lock: two deletes at once log once (the loser gets 404).
            $group = ModifierGroup::lockForUpdate()->findOrFail($group->getKey());
            $before = $group->snapshot();

            $productsDetached = $group->products()->detach();
            $group->options->each($this->deleteOption(...));
            $group->delete();

            $this->auditLogService->log(
                event: 'modifier_group.deleted',
                module: 'modifiers',
                auditable: $group,
                description: 'Grup modifier dihapus.',
                before: $before,
                meta: ['products_detached' => $productsDetached]
            );
        });
    }

    /** Soft delete without the recipe, so its ingredients stay deletable; the audit "before" already records it. */
    private function deleteOption(ModifierOption $option): void
    {
        $option->components()->detach();
        $option->delete();
    }
}
