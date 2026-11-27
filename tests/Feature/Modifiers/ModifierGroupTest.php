<?php

namespace Tests\Feature\Modifiers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Support\Modifiers\ModifierCatalog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// REQ-008 AC-01..AC-05, BR-1..BR-3: modifier groups, their options, attaching them to menus and the per-outlet switch.
class ModifierGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $cashier;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->manager = User::where('email', 'manager@gmail.com')->firstOrFail();
        $this->manager->markEmailAsVerified();
        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
    }

    private function product(array $attributes = []): Product
    {
        $n = ++$this->seq;

        return Product::create($attributes + [
            'title' => "Menu $n", 'sku' => "SKU-MOD-$n", 'barcode' => "BC-MOD-$n", 'description' => 'x',
            'image' => 'products/t.jpg', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'tax_rate' => 0, 'category_id' => $this->category->id,
        ]);
    }

    private function outlet(string $code): Outlet
    {
        return Outlet::create(['code' => $code, 'name' => "Outlet $code", 'is_active' => true, 'is_sales_enabled' => true]);
    }

    private function option(string $name, array $overrides = []): array
    {
        return $overrides + ['name' => $name, 'price_delta' => 0, 'is_default' => false];
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Ukuran', 'is_required' => true, 'min_select' => 1, 'max_select' => 1,
            'options' => [$this->option('Regular', ['is_default' => true]), $this->option('Large', ['price_delta' => 5000])],
        ];
    }

    private function createGroup(array $overrides = []): ModifierGroup
    {
        $this->actingAs($this->manager)
            ->post(route('settings.modifiers.store'), $this->payload($overrides))
            ->assertSessionHasNoErrors();

        return ModifierGroup::latest('id')->firstOrFail();
    }

    private function productPayload(Product $product, array $overrides = []): array
    {
        return $overrides + $product->only(['title', 'sku', 'barcode', 'description', 'category_id', 'buy_price', 'sell_price']) + ['tax_rate' => 0];
    }

    public function test_group_rules_are_enforced(): void
    {
        $cases = [
            [['min_select' => 3, 'max_select' => 2], 'min_select', 'min_above_max'],
            [['is_required' => false, 'min_select' => 0, 'max_select' => 0], 'max_select', 'max_below_one'],
            [['min_select' => 0], 'min_select', 'required_min'],
        ];

        foreach ($cases as [$overrides, $field, $message]) {
            $this->actingAs($this->manager)
                ->post(route('settings.modifiers.store'), $this->payload($overrides))
                ->assertSessionHasErrors([$field => __("messages.modifiers.$message")]);
        }

        $this->assertDatabaseCount('modifier_groups', 0);
    }

    public function test_option_rules_are_enforced(): void
    {
        $recipe = $this->product(['is_composite' => true]);
        $milk = $this->product(['is_raw_material' => true]);
        $other = $this->createGroup(['name' => 'Lain']);
        $default = $this->option('Regular', ['is_default' => true]);
        $line = ['product_id' => $milk->id, 'qty' => 1];

        $cases = [
            [[$default, $this->option('Large', ['price_delta' => -1])], 'options.1.price_delta', 'price_negative'],
            [[$default, $this->option('regular')], 'options.1.name', 'option_name_taken'],
            [[$default, $this->option('Large', ['is_default' => true])], 'options', 'one_default'],
            [[$this->option('Regular')], 'options', 'default_required'],
            [[$default + ['components' => [['product_id' => $recipe->id, 'qty' => 1]]]], 'options.0.components.0.product_id', 'component_invalid'],
            [[$default + ['components' => [$line, $line]]], 'options.0.components', 'component_duplicate'],
            [[$default + ['components' => [['product_id' => $milk->id, 'qty' => 0]]]], 'options.0.components.0.qty', 'qty_below_one'],
            // An option id of another group is never accepted.
            [[$default + ['id' => $other->options()->value('id')]], 'options.0.id', 'gone'],
        ];

        foreach ($cases as [$options, $field, $message]) {
            $this->post(route('settings.modifiers.store'), $this->payload(['options' => $options]))
                ->assertSessionHasErrors([$field => __("messages.modifiers.$message")]);
        }

        $this->assertDatabaseCount('modifier_groups', 1);

        // An optional group needs no default.
        $this->createGroup(['name' => 'Topping', 'is_required' => false, 'min_select' => 0, 'options' => [$this->option('Boba')]]);
    }

    public function test_groups_keep_their_order(): void
    {
        $size = $this->createGroup();
        $sugar = $this->createGroup(['name' => 'Gula']);
        $product = $this->product();

        $this->put(route('products.update', $product), $this->productPayload($product, ['modifier_group_ids' => [$sugar->id, $size->id]]))
            ->assertSessionHasNoErrors();

        $this->assertSame([$sugar->id, $size->id], $product->modifierGroups()->pluck('modifier_groups.id')->all());
        $this->assertSame(['Gula', 'Ukuran'], array_column(ModifierCatalog::forOutlet(null, collect([$product->id]))[$product->id], 'name'));
        $this->get(route('products.edit', $product))->assertInertia(fn (Assert $page) => $page
            ->has('modifier_groups', 2)
            ->where('product.modifier_groups.0.id', $sugar->id));

        // An edit that leaves the field out changes nothing; an empty one detaches.
        $this->put(route('products.update', $product), $this->productPayload($product));
        $this->assertSame(2, $product->modifierGroups()->count());
        $this->put(route('products.update', $product), $this->productPayload($product, ['modifier_group_ids' => []]));
        $this->assertSame(0, $product->modifierGroups()->count());

        // A new menu takes its groups too.
        $this->post(route('products.store'), $this->productPayload($product, [
            'sku' => 'SKU-NEW', 'barcode' => 'BC-NEW', 'stock' => 0, 'modifier_group_ids' => [$size->id],
        ]))->assertSessionHasNoErrors();
        $this->assertSame([$size->id], Product::where('sku', 'SKU-NEW')->firstOrFail()->modifierGroups()->pluck('modifier_groups.id')->all());
    }

    public function test_raw_material_cannot_take_modifiers(): void
    {
        $group = $this->createGroup();
        $product = $this->product();
        $raw = ['is_raw_material' => true, 'purchased_by' => 'central'];

        $this->put(route('products.update', $product), $this->productPayload($product, $raw + ['modifier_group_ids' => [$group->id]]))
            ->assertSessionHasErrors(['modifier_group_ids' => __('messages.modifiers.raw_material')]);
        $this->assertSame(0, $group->products()->count());

        // A menu that becomes a raw material on any channel loses its groups.
        $product->modifierGroups()->attach($group->id);
        $product->update(['is_raw_material' => true]);
        $this->assertSame(0, $group->products()->count());
    }

    public function test_disabled_option_is_not_offered_at_that_outlet(): void
    {
        $milk = $this->product(['is_raw_material' => true]);
        [$a, $b] = [$this->outlet('MOD-A'), $this->outlet('MOD-B')];
        $group = $this->createGroup(['options' => [
            $this->option('Regular', ['is_default' => true]),
            $this->option('Large', ['components' => [['product_id' => $milk->id, 'qty' => 50]], 'disabled_outlet_ids' => [$b->id]]),
        ]]);
        $menu = $this->product();
        $plain = $this->product();
        $menu->modifierGroups()->attach($group->id);
        $names = fn (Outlet $outlet) => array_column(ModifierCatalog::forOutlet($outlet, collect([$menu->id]))[$menu->id][0]['options'], 'name');

        $this->assertSame(['Regular', 'Large'], $names($a));
        $this->assertSame(['Regular'], $names($b));

        DB::enableQueryLog();
        $catalog = ModifierCatalog::forOutlet($a, collect([$menu->id, $plain->id]));
        $this->assertCount(4, DB::getQueryLog(), 'products, groups, options, components');
        $this->assertFalse($catalog->has($plain->id));
        $this->assertSame([['product_id' => $milk->id, 'qty' => 50.0]], $catalog[$menu->id][0]['options'][1]['components']);
    }

    public function test_deleting_keeps_products_sellable(): void
    {
        $group = $this->createGroup();
        $menu = $this->product();
        $menu->modifierGroups()->attach($group->id);
        $before = $menu->fresh()->toArray();

        $this->delete(route('settings.modifiers.destroy', $group))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($group);
        $this->assertSame(0, $group->options()->count());
        $this->assertSame(2, $group->options()->withTrashed()->count());
        $this->assertDatabaseCount('product_modifier_group', 0);
        $this->assertSame($before, $menu->fresh()->toArray());
        $this->assertTrue(ModifierCatalog::forOutlet(null, collect([$menu->id]))->isEmpty());
    }

    public function test_an_edit_syncs_the_options_and_deleted_ones_release_their_ingredients(): void
    {
        [$milk, $syrup] = [$this->product(['is_raw_material' => true]), $this->product(['is_raw_material' => true])];
        $recipe = fn (Product $product) => ['components' => [['product_id' => $product->id, 'qty' => 1]]];
        $group = $this->createGroup(['options' => [
            $this->option('Regular', ['is_default' => true] + $recipe($milk)),
            $this->option('Large', $recipe($syrup)),
        ]]);

        [$regular, $large] = $group->options()->get()->all();

        // The save keeps the id it sends, adds the new option and soft-deletes the one left out.
        $this->put(route('settings.modifiers.update', $group), $this->payload(['options' => [
            $this->option('Reguler', ['id' => $regular->id, 'is_default' => true] + $recipe($milk)),
            $this->option('Jumbo'),
        ]]))->assertSessionHasNoErrors();
        $this->assertSame(['Reguler', 'Jumbo'], $group->options()->pluck('name')->all());
        $this->assertSame($regular->id, $group->options()->first()->id);
        $this->assertSoftDeleted($large);
        $syrup->delete();

        $this->delete(route('settings.modifiers.destroy', $group));
        $milk->delete();

        $this->assertModelMissing($syrup);
        $this->assertModelMissing($milk);
        $deleted = AuditLog::where('event', 'modifier_group.deleted')->firstOrFail();
        $this->assertSame($milk->id, $deleted->before['options'][0]['components'][0]['product_id']);
    }

    public function test_every_change_is_audited(): void
    {
        $outlet = $this->outlet('MOD-B');
        $group = $this->createGroup();
        [$regular, $large] = $group->options()->get()->all();

        $this->put(route('settings.modifiers.update', $group), $this->payload(['options' => [
            $this->option('Regular', ['id' => $regular->id, 'is_default' => true]),
            $this->option('Large', ['id' => $large->id, 'price_delta' => 7000, 'disabled_outlet_ids' => [$outlet->id]]),
        ]]));
        $this->delete(route('settings.modifiers.destroy', $group));

        $logs = AuditLog::where('module', 'modifiers')->orderBy('id')->get();
        $this->assertSame(['modifier_group.created', 'modifier_group.updated', 'modifier_group.deleted'], $logs->pluck('event')->all());
        $this->assertSame([$this->manager->id], $logs->pluck('user_id')->unique()->all());
        $this->assertSame('Ukuran', $logs[0]->after['name']);
        $this->assertSame(5000, $logs[1]->before['options'][1]['price_delta']);
        $this->assertSame(7000, $logs[1]->after['options'][1]['price_delta']);
        $this->assertSame([], $logs[1]->before['options'][1]['disabled_outlet_ids']);
        $this->assertSame([$outlet->id], $logs[1]->after['options'][1]['disabled_outlet_ids']);
        $this->assertCount(2, $logs[2]->before['options']);

        // Attaching to a menu is part of the product's own update entry.
        $menu = $this->product();
        $other = $this->createGroup(['name' => 'Gula']);
        $this->put(route('products.update', $menu), $this->productPayload($menu, ['modifier_group_ids' => [$other->id]]));
        $log = AuditLog::where('event', 'product.updated')->latest('id')->firstOrFail();
        $this->assertSame([[], [$other->id]], [$log->before['modifier_group_ids'], $log->after['modifier_group_ids']]);
    }

    public function test_modifiers_need_the_permission(): void
    {
        $group = $this->createGroup();
        $menu = $this->product();

        $this->actingAs($this->cashier);
        $this->get(route('settings.modifiers.index'))->assertForbidden();
        $this->post(route('settings.modifiers.store'), $this->payload())->assertForbidden();
        $this->put(route('settings.modifiers.update', $group), $this->payload())->assertForbidden();
        $this->delete(route('settings.modifiers.destroy', $group))->assertForbidden();

        // products-edit alone saves the product; the groups it sends are ignored and none are listed.
        $editor = User::factory()->create();
        $editor->givePermissionTo(['products-access', 'products-edit']);
        $this->actingAs($editor)->get(route('products.edit', $menu))
            ->assertInertia(fn (Assert $page) => $page->where('modifier_groups', []));
        $this->put(route('products.update', $menu), $this->productPayload($menu, ['modifier_group_ids' => [$group->id]]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('products.index'));
        $this->assertSame(0, $menu->modifierGroups()->count());
    }

    public function test_the_seeder_grants_modifiers_manage_to_roles_that_edit_products(): void
    {
        foreach (['super-admin', 'manager', 'products-access'] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo('modifiers-manage'), $name);
        }
        $this->assertFalse(Role::findByName('cashier')->hasPermissionTo('modifiers-manage'));

        // A shop's own role gets it on the first run only, so a re-run never undoes the owner's choice.
        $own = Role::create(['name' => 'kepala-menu']);
        $own->givePermissionTo('products-edit');
        $this->seed(RoleSeeder::class);
        $this->assertFalse($own->fresh()->hasPermissionTo('modifiers-manage'));
        $this->assertTrue(Role::findByName('products-access')->hasPermissionTo('modifiers-manage'));

        Role::all()->each->revokePermissionTo('modifiers-manage');
        $this->seed(RoleSeeder::class);
        $this->assertTrue($own->fresh()->hasPermissionTo('modifiers-manage'));
    }
}
