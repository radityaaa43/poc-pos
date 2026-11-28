<?php

namespace Tests\Feature\DineIn;

use App\Models\DineOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\DineIn\Concerns\MakesPosTableOrders;
use Tests\Feature\DineIn\Concerns\ReservesTables;
use Tests\TestCase;

// #426 (REQ-009 AC-23, decision 19): a Terisi or Dipesan table can't be switched off or deleted, on web or API.
class DineTableRetireGuardTest extends TestCase
{
    use MakesPosTableOrders, RefreshDatabase, ReservesTables;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPosTable();

        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();
    }

    public function test_a_busy_table_cannot_be_deactivated_or_deleted(): void
    {
        $order = $this->sentOrder();

        $this->assertRefused(__('messages.dine_tables.in_use_order', ['order' => $order->id]));
    }

    public function test_a_waiting_qr_order_holds_the_table_too(): void
    {
        $order = DineOrder::create(['dine_table_id' => $this->table->id, 'status' => DineOrder::STATUS_SUBMITTED, 'subtotal' => 0, 'item_count' => 0]);

        $this->assertRefused(__('messages.dine_tables.in_use_order', ['order' => $order->id]));
    }

    public function test_a_reserved_table_names_the_reservation(): void
    {
        $this->reserve($this->table);

        $this->assertRefused(__('messages.dine_tables.in_use_reservation', ['reservation' => 5, 'time' => '19:00']));
    }

    public function test_a_free_table_can_be_retired_and_a_busy_one_renamed(): void
    {
        $order = $this->sentOrder();

        $this->actingAs($this->admin)->patch(route('dine-tables.update', $this->table), $this->fields(['name' => 'Meja Baru', 'is_active' => true]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Meja Baru', $this->table->fresh()->name);

        $order->update(['status' => DineOrder::STATUS_COMPLETED]);
        $this->patch(route('dine-tables.update', $this->table), $this->fields(['is_active' => false]))->assertSessionHasNoErrors();
        $this->assertFalse($this->table->fresh()->is_active);
        $this->delete(route('dine-tables.destroy', $this->table))->assertSessionHasNoErrors();
        $this->assertModelMissing($this->table);
    }

    // A table order locks the table row before it creates the order; retiring locks the same row before it looks.
    public function test_retire_and_order_take_the_same_table_lock(): void
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql) {
            if (DB::transactionLevel() > 1) {
                $sql[] = $query->sql;
            }
        });

        $this->actingAs($this->admin)->delete(route('dine-tables.destroy', $this->table))->assertSessionHasNoErrors();

        $this->assertMatchesRegularExpression('/^select \* from .dine_tables. where/', $sql[0]);
        $this->assertMatchesRegularExpression('/from .dine_orders./', $sql[1]);
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', $sql[0]);
        }
    }

    /** All four paths refuse with the message, and the table stays and stays on. */
    private function assertRefused(string $message): void
    {
        $this->actingAs($this->admin);
        $this->patch(route('dine-tables.update', $this->table), $this->fields(['is_active' => false]))->assertSessionHasErrors(['table' => $message]);
        $this->delete(route('dine-tables.destroy', $this->table))->assertSessionHasErrors(['table' => $message]);

        Sanctum::actingAs($this->admin, ['*']);
        $this->patchJson(route('api.dine-tables.update', $this->table), $this->fields(['is_active' => false]))->assertJsonValidationErrors(['table' => $message]);
        $this->deleteJson(route('api.dine-tables.destroy', $this->table))->assertJsonValidationErrors(['table' => $message]);

        $this->assertTrue($this->table->fresh()->is_active);
    }

    /** @return array<string, mixed> */
    private function fields(array $overrides): array
    {
        return $overrides + ['dine_area_id' => $this->table->dine_area_id, 'name' => $this->table->name];
    }
}
