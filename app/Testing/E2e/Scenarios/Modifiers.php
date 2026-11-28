<?php

namespace App\Testing\E2e\Scenarios;

use App\Services\ModifierGroupService;

class Modifiers
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // A required group --arg=name=<text> with Regular (default) and Large.
            'modifier-group' => function (array $options): array {
                $group = app(ModifierGroupService::class)->save(null, [
                    'name' => $options['name'],
                    'is_required' => true, 'min_select' => 1, 'max_select' => 1,
                    'options' => [
                        ['name' => 'Regular', 'price_delta' => 0, 'is_default' => true],
                        ['name' => 'Large', 'price_delta' => 5000, 'is_default' => false],
                    ],
                ]);

                return ['group_id' => $group->id, 'name' => $group->name];
            },
        ];
    }
}
