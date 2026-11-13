<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Checks a drink selection against the database. Nothing from the client is trusted:
 * each option id is looked up and checked against the drink's own groups, so group
 * keys, prices, and names from the request are never used.
 *
 * Error keys:
 *   item               the drink itself (sold out, category inactive)
 *   quantity           outside 1-10
 *   selected           problems with no valid group (bad, unknown, foreign, or duplicate ids)
 *   selected.{groupId} problems within one group (sold-out option, too few, too many)
 */
final class ModifierValidator
{
    public const MIN_QUANTITY = 1;

    public const MAX_QUANTITY = 10;

    /**
     * @param  array<array-key, mixed>  $optionIds  raw ids from the request
     *
     * @throws ValidationException
     */
    public function validate(MenuItem $item, array $optionIds, int $quantity): ValidatedDrink
    {
        $item->loadMissing(MenuItem::builderRelations());

        if (! $item->category->is_active) {
            throw ValidationException::withMessages(['item' => __('This drink is not available.')]);
        }

        if (! $item->is_available) {
            throw ValidationException::withMessages(['item' => __('This drink is sold out.')]);
        }

        $errors = [];

        if ($quantity < self::MIN_QUANTITY || $quantity > self::MAX_QUANTITY) {
            $errors['quantity'][] = __('Choose a quantity from :min to :max.', ['min' => self::MIN_QUANTITY, 'max' => self::MAX_QUANTITY]);
        }

        $ids = [];

        foreach ($optionIds as $id) {
            if (is_int($id) && $id > 0) {
                $ids[] = $id;
            } elseif (is_string($id) && ctype_digit($id) && $id !== '0') {
                $ids[] = (int) $id;
            } else {
                $errors['selected'][] = __('One of the chosen options is invalid.');
            }
        }

        if (count($ids) !== count(array_unique($ids))) {
            $errors['selected'][] = __('The same option was chosen more than once.');
        }

        $ids = array_values(array_unique($ids));

        /** @var Collection<int, ModifierOption> $drinkOptions keyed by option id */
        $drinkOptions = $item->modifierGroups
            ->flatMap(fn (ModifierGroup $group) => $group->options)
            ->keyBy('id');

        $foreignIds = array_values(array_filter($ids, fn (int $id): bool => ! $drinkOptions->has($id)));

        if ($foreignIds !== []) {
            $existing = ModifierOption::query()->whereKey($foreignIds)->count();

            if ($existing < count($foreignIds)) {
                $errors['selected'][] = __('One of the chosen options does not exist.');
            }

            if ($existing > 0) {
                $errors['selected'][] = __('One of the chosen options is not offered for this drink.');
            }
        }

        $chosen = $drinkOptions->only($ids);

        foreach ($item->modifierGroups as $group) {
            $inGroup = $chosen->where('modifier_group_id', $group->id);
            $key = 'selected.'.$group->id;

            foreach ($inGroup as $option) {
                if (! $option->is_available) {
                    $errors[$key][] = __(':option is sold out.', ['option' => $option->name_en]);
                }
            }

            $count = $inGroup->count();
            $min = $group->effectiveMin();

            if ($count < $min) {
                $errors[$key][] = $min === 1
                    ? __('Choose an option for :group.', ['group' => $group->name_en])
                    : __('Choose at least :min options for :group.', ['min' => $min, 'group' => $group->name_en]);
            }

            if ($count > $group->max_select) {
                $errors[$key][] = $group->max_select === 1
                    ? __('Choose only one option for :group.', ['group' => $group->name_en])
                    : __('Choose at most :max options for :group.', ['max' => $group->max_select, 'group' => $group->name_en]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(
                array_map(fn (array $messages): array => array_values(array_unique($messages)), $errors),
            );
        }

        sort($ids);

        return new ValidatedDrink(
            item: $item,
            optionIds: $ids,
            quantity: $quantity,
            options: $drinkOptions->only($ids)->values(),
        );
    }
}
