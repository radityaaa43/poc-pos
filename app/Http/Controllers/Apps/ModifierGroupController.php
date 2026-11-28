<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveModifierGroupRequest;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\ModifierGroupService;
use Inertia\Inertia;

class ModifierGroupController extends Controller
{
    public function __construct(
        protected readonly ModifierGroupService $groups,
    ) {}

    public function index()
    {
        return Inertia::render('Dashboard/Settings/Modifiers', [
            'groups' => ModifierGroup::with('options.components', 'options.disabledOutlets')
                ->withCount('products')
                ->orderBy('name')
                ->get()
                ->map(fn (ModifierGroup $group) => [
                    'id' => $group->id,
                    'products_count' => $group->getAttribute('products_count'),
                    ...$group->snapshot(),
                ]),
            'outlets' => Outlet::orderBy('code')->get(['id', 'code', 'name']),
            'ingredients' => Product::select(['id', 'title'])->recipeComponents()->get(),
        ]);
    }

    public function store(SaveModifierGroupRequest $request)
    {
        $this->groups->save(null, $request->validated());

        return back();
    }

    public function update(SaveModifierGroupRequest $request, ModifierGroup $modifierGroup)
    {
        $this->groups->save($modifierGroup, $request->validated());

        return back();
    }

    public function destroy(ModifierGroup $modifierGroup)
    {
        $this->groups->delete($modifierGroup);

        return back();
    }
}
