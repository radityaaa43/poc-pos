<?php

namespace Tests\Feature\DineIn;

use App\Models\AuditLog;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DineReservation;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\User;
use App\Services\DineReservationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Table reservations (REQ-009 AC-16, AC-18, AC-19, AC-21, AC-22, BR-1, BR-2; issue #431 BR-3, BR-4). */
class DineReservationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+62 812-0000-1111';

    private Outlet $outlet;

    private User $staff;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-12-10 10:00:00'));
        $this->outlet = $this->outletNamed('OUT-A');
        $this->staff = $this->userAt($this->outlet, ['dine-reservations-manage']);
        $this->table = $this->tableAt($this->outlet, 'Meja 1');
    }

    public function test_a_reservation_is_saved_with_every_field(): void
    {
        $this->book()->assertSessionHasNoErrors()->assertRedirect();

        $reservation = DineReservation::sole();
        $this->assertEquals($this->outlet->id, $reservation->outlet_id);
        $this->assertSame($this->table->id, $reservation->dine_table_id);
        $this->assertSame(
            ['Budi', self::PHONE, 4, '2026-12-10 19:00:00', 'Dekat jendela', 'booked'],
            [$reservation->guest_name, $reservation->phone, $reservation->party_size, $reservation->reserved_at->format('Y-m-d H:i:s'), $reservation->note, $reservation->status]
        );
    }

    public function test_a_second_booking_less_than_two_hours_apart_is_refused(): void
    {
        $this->reservation();

        foreach (['17:01', '20:59'] as $time) {
            $this->book(['guest_name' => 'Sari', 'reserved_time' => $time])->assertSessionHasErrors('reserved_time');
            $this->assertStringContainsString('Budi', session('errors')->first('reserved_time'));
        }

        $this->assertDatabaseCount('dine_reservations', 1);
    }

    public function test_exactly_two_hours_apart_or_another_table_or_day_is_not_a_clash(): void
    {
        $this->reservation();
        $other = $this->tableAt($this->outlet, 'Meja 2');

        foreach ([['reserved_time' => '17:00'], ['reserved_time' => '21:00'], ['dine_table_id' => $other->id], ['reserved_date' => '2026-12-11']] as $change) {
            $this->book($change)->assertSessionHasNoErrors();
        }

        $this->assertDatabaseCount('dine_reservations', 5);
    }

    public function test_cancelled_and_no_show_free_the_slot_but_arrived_holds_it(): void
    {
        foreach (['cancelled', 'no_show'] as $status) {
            $table = $this->tableAt($this->outlet, "Meja {$status}");
            $this->reservation(['dine_table_id' => $table->id, 'status' => $status]);
            $this->book(['dine_table_id' => $table->id])->assertSessionHasNoErrors();
        }

        $this->reservation(['status' => 'arrived']);
        $this->book()->assertSessionHasErrors('reserved_time');
    }

    public function test_changing_a_reservation_checks_the_clash_but_not_against_itself(): void
    {
        $reservation = $this->reservation();
        $this->reservation(['guest_name' => 'Sari', 'reserved_at' => '2026-12-10 22:00:00']);

        $this->change($reservation, ['reserved_time' => '19:30'])->assertSessionHasNoErrors();
        $this->change($reservation, ['reserved_time' => '20:30'])->assertSessionHasErrors('reserved_time');

        $this->assertSame('19:30', $reservation->fresh()->reserved_at->format('H:i'));
    }

    public function test_the_table_row_is_locked_before_the_clash_query(): void
    {
        $this->assertLockedBefore(fn () => $this->book()->assertSessionHasNoErrors(), 'dine_tables', 'from [`"]dine_reservations');
    }

    public function test_removing_a_table_locks_its_row_before_looking_for_a_reservation(): void
    {
        $remove = fn () => app(DineReservationService::class)->deleteTable($this->table, $this->staff, $this->outlet);

        $this->assertLockedBefore($remove, 'dine_tables', 'from [`"]dine_reservations');
    }

    public function test_change_and_mark_lock_the_reservation_before_writing_it(): void
    {
        $reservation = $this->reservation();
        $service = app(DineReservationService::class);

        foreach ([
            fn () => $service->update($reservation, $this->outlet, $this->form(['note' => 'Teras']), $this->staff),
            fn () => $service->mark($reservation, 'arrived', $this->staff),
        ] as $act) {
            $this->assertLockedBefore($act, 'dine_reservations', '^update [`"]dine_reservations');
        }
    }

    /** #431 BR-4: whoever cannot see that outlet's reservations learns the outlet and the time, never the guest. */
    public function test_a_refusal_never_names_a_guest_the_reader_cannot_see(): void
    {
        $shared = $this->tableAt(null, 'Teras');
        $this->reservation(['dine_table_id' => $shared->id, 'guest_name' => 'Tamu Rahasia']);
        $other = $this->userAt($this->outletNamed('OUT-B'), ['dine-reservations-manage', 'dine-tables-update', 'dine-tables-delete']);
        $edit = ['name' => 'Teras', 'is_active' => false];
        $messages = [
            'id' => [
                'Meja ini punya reservasi outlet OUT-A pada 10/12/2026 19:00 — tandai atau batalkan dulu',
                'Meja ini sudah direservasi outlet OUT-A pada 10/12/2026 19:00. Beri jarak minimal 2 jam.',
            ],
            'en' => [
                'This table has a reservation of outlet OUT-A on 10/12/2026 19:00 — mark or cancel it first',
                'This table is already reserved by outlet OUT-A on 10/12/2026 19:00. Leave at least 2 hours between bookings.',
            ],
        ];

        foreach ($messages as $locale => [$removal, $clash]) {
            $other->update(['locale' => $locale]);
            $this->actingAs($other)->delete(route('dine-tables.destroy', $shared))->assertSessionHasErrors(['table' => $removal]);
            $this->patch(route('dine-tables.update', $shared), $edit)->assertSessionHasErrors(['table' => $removal]);
            $this->book(['dine_table_id' => $shared->id, 'reserved_time' => '20:00'], $other)->assertSessionHasErrors(['reserved_time' => $clash]);
        }

        // Their own outlet is not enough without the reservation permission.
        $waiter = $this->userAt($this->outlet, ['dine-tables-delete']);
        $waiter->update(['locale' => 'en']);
        $this->actingAs($waiter)->delete(route('dine-tables.destroy', $shared))->assertSessionHasErrors(['table' => $messages['en'][0]]);

        Sanctum::actingAs($other, ['*']);
        foreach ($messages as $locale => [$removal]) {
            app()->setLocale($locale); // the API has no locale middleware
            $this->deleteJson(route('api.dine-tables.destroy', $shared))->assertStatus(422)->assertJsonPath('errors.table.0', $removal);
            $this->patchJson(route('api.dine-tables.update', $shared), $edit)->assertStatus(422)->assertJsonPath('errors.table.0', $removal);
        }
    }

    public function test_a_booking_stays_editable_after_its_shared_table_moves_to_another_outlet(): void
    {
        $outletB = $this->outletNamed('OUT-B');
        $staffB = $this->userAt($outletB, ['dine-reservations-manage']);
        $shared = $this->tableAt(null, 'Teras');
        $reservation = $this->reservation(['outlet_id' => $outletB->id, 'dine_table_id' => $shared->id]);
        $shared->update(['dine_area_id' => $this->table->dine_area_id]); // now outlet A's

        $this->change($reservation, ['dine_table_id' => $shared->id, 'phone' => '0813 9999 2222'], $staffB)->assertSessionHasNoErrors();
        $this->assertSame('0813 9999 2222', $reservation->fresh()->phone);
        // Moving the booking onto a table that is not theirs is still refused.
        $this->change($reservation, ['dine_table_id' => $this->table->id], $staffB)->assertNotFound();
    }

    public function test_table_with_a_booked_reservation_cannot_be_deleted_or_deactivated(): void
    {
        $manager = $this->userAt($this->outlet, ['dine-reservations-manage', 'dine-tables-update', 'dine-tables-delete']);
        $manager->update(['locale' => 'id']);
        $edit = ['dine_area_id' => $this->table->dine_area_id, 'name' => 'Meja 1', 'is_active' => false];

        // An earlier day, never marked: still in the way.
        $overdue = $this->reservation(['reserved_at' => '2026-12-08 19:00:00']);
        $refusal = ['table' => 'Meja ini punya reservasi Budi 08/12/2026 19:00 — tandai atau batalkan dulu'];
        $this->actingAs($manager)->delete(route('dine-tables.destroy', $this->table))->assertSessionHasErrors($refusal);
        $this->patch(route('dine-tables.update', $this->table), $edit)->assertSessionHasErrors($refusal);

        // Weeks ahead, far outside the 30-minute window: in the way too.
        $overdue->update(['status' => 'arrived']);
        $ahead = $this->reservation(['guest_name' => 'Sari', 'reserved_at' => '2027-01-05 19:00:00']);
        Sanctum::actingAs($manager, ['*']);
        foreach ([
            $this->deleteJson(route('api.dine-tables.destroy', $this->table)),
            $this->patchJson(route('api.dine-tables.update', $this->table), $edit),
        ] as $response) {
            $response->assertStatus(422)->assertJsonValidationErrors('table');
            $this->assertStringContainsString('Sari 05/01/2027 19:00', $response->json('errors.table.0'));
        }

        $this->assertTrue($this->table->fresh()->is_active);
        $this->assertSame('booked', $ahead->fresh()->status);

        // An edit that leaves the table on is saved; once nothing is unmarked it can be switched off.
        $this->patchJson(route('api.dine-tables.update', $this->table), ['name' => 'Meja Depan', 'is_active' => true] + $edit)->assertOk();
        $ahead->update(['status' => 'cancelled']);
        $this->patchJson(route('api.dine-tables.update', $this->table), $edit)->assertOk();
        $this->assertFalse($this->table->fresh()->is_active);
    }

    public function test_marked_reservations_survive_their_table_being_deleted(): void
    {
        $manager = $this->userAt($this->outlet, ['dine-reservations-manage', 'dine-tables-delete']);
        foreach (DineReservation::MARKS as $status) {
            $this->reservation(['status' => $status]);
        }

        $this->actingAs($manager)->delete(route('dine-tables.destroy', $this->table))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('dine_tables', ['id' => $this->table->id]);
        $this->assertSame(3, DineReservation::whereNull('dine_table_id')->count());
        $this->get(route('dine-reservations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reservations', 3)
                ->where('reservations.0.guest_name', 'Budi')
                ->where('reservations.0.dine_table_id', null)
                ->where('reservations.0.table_name', null)
                ->where('reservations.0.area_name', null));
    }

    public function test_invalid_input_and_foreign_or_inactive_tables_are_refused(): void
    {
        $inactive = $this->tableAt($this->outlet, 'Meja Mati', false);

        foreach ([
            ['guest_name' => ''], ['phone' => 'telepon saya'], ['party_size' => 0], ['party_size' => 101],
            ['reserved_date' => '10/12/2026'], ['reserved_time' => '7 pm'], ['dine_table_id' => $inactive->id],
        ] as $invalid) {
            $this->book($invalid)->assertSessionHasErrors(array_key_first($invalid));
        }

        $foreign = $this->tableAt($this->outletNamed('OUT-B'), 'Meja B');
        $this->book(['dine_table_id' => $foreign->id])->assertNotFound();

        $this->assertDatabaseCount('dine_reservations', 0);
    }

    public function test_a_shared_table_clashes_across_outlets_but_lists_per_outlet(): void
    {
        $shared = $this->tableAt(null, 'Teras');
        $staffB = $this->userAt($this->outletNamed('OUT-B'), ['dine-reservations-manage']);

        $this->book(['dine_table_id' => $shared->id])->assertSessionHasNoErrors();
        $this->book(['dine_table_id' => $shared->id, 'reserved_time' => '20:00'], $staffB)->assertSessionHasErrors('reserved_time');

        $this->actingAs($staffB)->get(route('dine-reservations.index'))
            ->assertInertia(fn (Assert $page) => $page->has('reservations', 0));
    }

    public function test_booked_soon_covers_30_minutes_before_until_marked(): void
    {
        $reservation = $this->reservation();
        $at = Carbon::parse('2026-12-10 19:00:00');

        $this->assertFalse(DineReservation::bookedSoon($at->copy()->subMinutes(31))->exists());
        $this->assertTrue(DineReservation::bookedSoon($at->copy()->subMinutes(30))->exists());
        // Decision 8: nobody marked it, so the table stays reserved long after the time.
        $this->assertTrue(DineReservation::bookedSoon($at->copy()->addDays(3))->exists());

        foreach (DineReservation::MARKS as $status) {
            $reservation->update(['status' => $status]);
            $this->assertFalse(DineReservation::bookedSoon($at)->exists());
        }
    }

    public function test_booked_soon_by_table_is_one_query_with_the_earliest_per_table(): void
    {
        $later = $this->tableAt($this->outlet, 'Meja 2');
        $this->reservation(['guest_name' => 'Late', 'reserved_at' => '2026-12-10 10:20:00']);
        $this->reservation(['guest_name' => 'Earlier', 'reserved_at' => '2026-12-09 12:00:00']);
        $this->reservation(['dine_table_id' => $later->id]); // 19:00 is not soon at 10:00

        DB::flushQueryLog();
        DB::enableQueryLog();
        $byTable = DineReservation::bookedSoonByTable([$this->table->id, $later->id]);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([$this->table->id], $byTable->keys()->all());
        $this->assertSame('Earlier', $byTable[$this->table->id]->guest_name);
    }

    public function test_the_blocking_reservation_is_the_earliest_booked_one_of_any_date(): void
    {
        $this->assertNull($this->table->blockingReservation());

        $this->reservation(['guest_name' => 'Far', 'reserved_at' => '2027-01-05 19:00:00']);
        $this->reservation(['guest_name' => 'Near', 'reserved_at' => '2026-12-20 19:00:00']);
        $this->reservation(['guest_name' => 'Gone', 'reserved_at' => '2026-12-11 19:00:00', 'status' => 'cancelled']);

        $this->assertSame('Near', $this->table->blockingReservation()?->guest_name);
    }

    public function test_marking_changes_the_status_and_releases_the_table(): void
    {
        foreach (DineReservation::MARKS as $day => $status) {
            $reservation = $this->reservation(['reserved_at' => "2026-12-1{$day} 19:00:00"]);

            $this->mark($reservation, $status)->assertSessionHasNoErrors();

            $this->assertSame($status, $reservation->fresh()->status);
            $this->assertFalse(DineReservation::bookedSoon($reservation->reserved_at)->whereKey($reservation->id)->exists());
        }

        $reservation = $this->reservation(['reserved_at' => '2026-12-20 19:00:00']);
        $this->mark($reservation, 'booked')->assertSessionHasErrors('status');
    }

    public function test_a_marked_reservation_cannot_be_marked_or_changed_again(): void
    {
        $reservation = $this->reservation(['status' => 'arrived']);

        $this->mark($reservation, 'cancelled')->assertSessionHasErrors('status');
        $this->change($reservation, ['guest_name' => 'Sari'])->assertSessionHasErrors('status');

        $this->assertSame(['arrived', 'Budi'], [$reservation->fresh()->status, $reservation->fresh()->guest_name]);
        $this->assertSame(0, AuditLog::where('module', 'dine_reservations')->count());
    }

    public function test_the_list_is_per_outlet_and_date(): void
    {
        $outletB = $this->outletNamed('OUT-B');
        $tableB = $this->tableAt($outletB, 'Meja B');
        $this->reservation();
        $this->reservation(['guest_name' => 'Besok', 'reserved_at' => '2026-12-11 12:00:00']);
        $foreign = $this->reservation(['outlet_id' => $outletB->id, 'dine_table_id' => $tableB->id, 'guest_name' => 'Lain']);

        $this->actingAs($this->staff)->get(route('dine-reservations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/DineIn/Reservations/Index')
                ->where('date', '2026-12-10')
                ->has('reservations', 1)
                ->where('reservations.0.guest_name', 'Budi')
                ->where('reservations.0.phone', self::PHONE)
                ->where('reservations.0.date', '2026-12-10')
                ->where('reservations.0.time', '19:00')
                ->where('reservations.0.table_name', 'Meja 1')
                ->where('reservations.0.status', 'booked')
                ->has('tables', 1));
        $this->get(route('dine-reservations.index', ['date' => '2026-12-11']))
            ->assertInertia(fn (Assert $page) => $page->has('reservations', 1)->where('reservations.0.guest_name', 'Besok'));

        $this->change($foreign, [])->assertNotFound();
        $this->mark($foreign, 'arrived')->assertNotFound();
        $this->assertSame('booked', $foreign->fresh()->status);
    }

    public function test_today_includes_unmarked_reservations_of_earlier_days(): void
    {
        $this->reservation(['guest_name' => 'Lupa', 'reserved_at' => '2026-12-08 19:00:00']);
        $this->reservation(['guest_name' => 'Selesai', 'reserved_at' => '2026-12-08 12:00:00', 'status' => 'arrived']);

        $this->actingAs($this->staff)->get(route('dine-reservations.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reservations', 1)
                ->where('reservations.0.guest_name', 'Lupa')
                ->where('reservations.0.date', '2026-12-08'));
        $this->get(route('dine-reservations.index', ['date' => '2026-12-11']))
            ->assertInertia(fn (Assert $page) => $page->has('reservations', 0));
    }

    public function test_the_phone_is_hidden_from_model_serialisation(): void
    {
        $reservation = $this->reservation();

        $this->assertArrayNotHasKey('phone', $reservation->toArray());
        $this->assertStringNotContainsString('812-0000-1111', $this->table->load('reservations')->toJson());
    }

    public function test_no_print_path_or_public_page_reads_reservations(): void
    {
        $printPaths = [
            ...glob(app_path('Services/*Print*.php')),
            ...glob(app_path('Services/*Ticket*.php')),
            ...glob(resource_path('js/Components/Receipt/*.jsx')),
            ...glob(resource_path('views/pdf/*.blade.php')),
            resource_path('js/Pages/Dashboard/Transactions/Print.jsx'),
            resource_path('js/Pages/Public/DineOrderStatus.jsx'),
        ];
        $this->assertNotEmpty($printPaths);
        foreach ($printPaths as $file) {
            $this->assertDoesNotMatchRegularExpression('/reservation/i', file_get_contents($file), $file);
        }

        $this->reservation(['guest_name' => 'Tamu Rahasia', 'reserved_at' => '2026-12-10 10:15:00']);

        $order = DineOrder::create(['dine_table_id' => $this->table->id, 'status' => DineOrder::STATUS_SUBMITTED, 'payment_option' => 'pay_at_counter', 'subtotal' => 0, 'item_count' => 0, 'submitted_at' => now()]);
        foreach ([route('dine.menu', $this->table->token), route('dine-order.status', $order->access_token)] as $page) {
            $this->get($page)->assertOk()->assertDontSee('812-0000-1111')->assertDontSee('Tamu Rahasia');
        }
    }

    public function test_create_change_and_mark_are_audited_once_each(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $reservation = DineReservation::sole();

        $this->change($reservation, [])->assertSessionHasNoErrors(); // nothing changed: nothing logged
        $this->assertSame(1, AuditLog::where('module', 'dine_reservations')->count());

        $this->change($reservation, ['note' => 'Pindah ke teras'])->assertSessionHasNoErrors();
        $this->mark($reservation, 'arrived')->assertSessionHasNoErrors();

        $logs = AuditLog::where('module', 'dine_reservations')->orderBy('id')->get();
        $this->assertSame(['dine_reservation.created', 'dine_reservation.updated', 'dine_reservation.marked'], $logs->pluck('event')->all());
        $this->assertSame([$this->staff->id], $logs->pluck('user_id')->unique()->values()->all());
        $this->assertSame('Meja 1', $logs[0]->after['table']);
        $this->assertSame('2026-12-10 19:00', $logs[0]->after['reserved_at']);
        $this->assertSame('Dekat jendela', $logs[1]->before['note']);
        $this->assertSame('Pindah ke teras', $logs[1]->after['note']);
        $this->assertArrayNotHasKey('phone_changed', $logs[1]->after);
        $this->assertSame('booked', $logs[2]->before['status']);
        $this->assertSame('arrived', $logs[2]->after['status']);
    }

    public function test_the_audit_log_never_holds_the_phone_number(): void
    {
        $this->book()->assertSessionHasNoErrors();
        $reservation = DineReservation::sole();
        $this->change($reservation, ['phone' => '0813 9999 2222', 'guest_name' => 'Sari'])->assertSessionHasNoErrors();
        $this->mark($reservation, 'no_show')->assertSessionHasNoErrors();

        $logs = AuditLog::where('module', 'dine_reservations')->orderBy('id')->get();
        $this->assertCount(3, $logs);
        $this->assertTrue($logs[0]->after['phone_changed']);
        $this->assertTrue($logs[1]->after['phone_changed']);
        $this->assertTrue($logs[1]->after['guest_name_changed']);
        $this->assertDoesNotMatchRegularExpression('/Budi|Sari/', $logs->toJson()); // BR-4: the log is not outlet-bound
        $this->assertStringNotContainsString('812-0000-1111', $logs->toJson());
        $this->assertStringNotContainsString('9999 2222', $logs->toJson());
    }

    public function test_without_the_permission_every_reservation_route_answers_403(): void
    {
        $reservation = $this->reservation();
        $other = $this->userAt($this->outlet, ['dine-tables-access', 'dine-orders-access']);

        $this->actingAs($other)->get(route('dine-reservations.index'))->assertForbidden();
        $this->book([], $other)->assertForbidden();
        $this->change($reservation, [], $other)->assertForbidden();
        $this->mark($reservation, 'arrived', $other)->assertForbidden();

        $this->assertDatabaseCount('dine_reservations', 1);
        $this->assertSame('booked', $reservation->fresh()->status);
    }

    public function test_seeders_grant_the_permission_to_the_table_roles_only(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        foreach (['super-admin', 'manager', 'dine-tables-access'] as $role) {
            $this->assertTrue(Role::findByName($role)->hasPermissionTo('dine-reservations-manage'), $role);
        }
        foreach (['cashier', 'dine-orders-access'] as $role) {
            $this->assertFalse(Role::findByName($role)->hasPermissionTo('dine-reservations-manage'), $role);
        }
    }

    public function test_reservation_messages_exist_in_id_and_en(): void
    {
        $id = trans('messages.dine_reservations', [], 'id');
        $en = trans('messages.dine_reservations', [], 'en');

        $this->assertSame(array_keys($en), array_keys($id));
        foreach ($en as $key => $text) {
            $this->assertNotSame($text, $id[$key], $key);
        }
    }

    public function test_times_follow_the_app_timezone(): void
    {
        $zone = date_default_timezone_get();
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');

        try {
            $this->travelTo(Carbon::parse('2026-12-10 18:40:00', 'Asia/Jakarta'));
            $this->book()->assertSessionHasNoErrors();

            $this->assertSame('2026-12-10 19:00:00', DB::table('dine_reservations')->value('reserved_at'));
            $this->assertTrue(DineReservation::bookedSoon()->exists());
            $this->get(route('dine-reservations.index'))
                ->assertInertia(fn (Assert $page) => $page->where('date', '2026-12-10')->where('reservations.0.time', '19:00'));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function test_an_outlet_with_a_reservation_cannot_be_deleted(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);
        $this->staff->assignRole('super-admin');
        $table = $this->tableAt(null, 'Teras');

        foreach (['booked', 'arrived'] as $status) {
            // No warehouse and no sale: only the reservation keeps this outlet.
            $outlet = $this->outletNamed("OUT-{$status}");
            $reservation = $this->reservation(['outlet_id' => $outlet->id, 'dine_table_id' => $table->id, 'status' => $status]);

            $this->withSession($this->recentlyConfirmedSession())->actingAs($this->staff)
                ->delete(route('settings.outlets.destroy', $outlet))
                ->assertSessionHas('error');

            $this->assertModelExists($outlet);
            $this->assertModelExists($reservation);
        }
    }

    public function test_the_month_summary_is_empty_without_reservations(): void
    {
        $this->assertSame(
            ['month' => '2026-12', 'made' => 0, 'open' => 0, 'arrived' => 0, 'cancelled' => 0, 'no_show' => 0, 'no_show_percent' => null],
            $this->monthSummary()
        );
    }

    public function test_the_no_show_percent_needs_an_arrived_or_no_show_reservation(): void
    {
        $this->reservation(['status' => 'cancelled']);
        $this->reservation(['guest_name' => 'Sari', 'reserved_at' => '2026-12-11 12:00:00']);

        $summary = $this->monthSummary();

        $this->assertSame([2, 0, 1, 1], [$summary['made'], $summary['arrived'], $summary['cancelled'], $summary['open']]);
        $this->assertNull($summary['no_show_percent']);
    }

    public function test_the_no_show_percent_is_no_show_over_arrived_plus_no_show(): void
    {
        $this->reservation(['status' => 'no_show']);
        $this->reservation(['guest_name' => 'Sari', 'status' => 'arrived', 'reserved_at' => '2026-12-11 12:00:00']);
        $this->reservation(['guest_name' => 'Tono', 'status' => 'arrived', 'reserved_at' => '2026-12-12 12:00:00']);
        $this->reservation(['guest_name' => 'Umar', 'status' => 'cancelled', 'reserved_at' => '2026-12-13 12:00:00']);

        $summary = $this->monthSummary();

        $this->assertSame([0, 2, 1, 1], [$summary['open'], $summary['arrived'], $summary['cancelled'], $summary['no_show']]);
        $this->assertSame(33.3, $summary['no_show_percent']);
    }

    public function test_the_month_is_cut_by_reserved_at_at_midnight(): void
    {
        $this->reservation(['guest_name' => 'November', 'status' => 'arrived', 'reserved_at' => '2026-11-30 23:59:59']);
        $this->reservation(['guest_name' => 'Desember', 'reserved_at' => '2026-12-01 00:00:00']);

        $november = $this->monthSummary('2026-11-15');
        $december = $this->monthSummary('2026-12-01');

        $this->assertSame([1, 0], [$november['arrived'], $november['open']]);
        $this->assertSame([0, 1], [$december['arrived'], $december['open']]);
    }

    public function test_the_month_summary_is_per_outlet(): void
    {
        $outletB = $this->outletNamed('OUT-B');
        $this->reservation(['status' => 'arrived']);
        $this->reservation(['outlet_id' => $outletB->id, 'dine_table_id' => $this->tableAt($outletB, 'Meja B')->id, 'status' => 'no_show', 'guest_name' => 'Lain']);
        // A table shared by outlets: the booking is still OUT-B's.
        $this->reservation(['outlet_id' => $outletB->id, 'dine_table_id' => $this->tableAt(null, 'Teras')->id, 'status' => 'no_show', 'guest_name' => 'Rahasia', 'reserved_at' => '2026-12-11 12:00:00']);

        $summary = $this->monthSummary();

        $this->assertSame([1, 0, 0, 0], [$summary['arrived'], $summary['no_show'], $summary['cancelled'], $summary['open']]);
    }

    public function test_a_reservation_of_a_deleted_table_still_counts(): void
    {
        $reservation = $this->reservation(['status' => 'no_show']);
        $this->table->delete();

        $this->assertNull($reservation->fresh()->dine_table_id);
        $this->assertSame(1, $this->monthSummary()['no_show']);
    }

    public function test_the_summary_follows_the_month_of_the_shown_date(): void
    {
        $this->reservation(['guest_name' => 'Desember']);
        $this->reservation(['guest_name' => 'Januari', 'reserved_at' => '2027-01-05 12:00:00']);

        $this->actingAs($this->staff)->get(route('dine-reservations.index', ['date' => '2026-12-11']))
            ->assertInertia(fn (Assert $page) => $page->where('summary.month', '2026-12')->where('summary.open', 1));
        $this->get(route('dine-reservations.index', ['date' => '2027-01-02']))
            ->assertInertia(fn (Assert $page) => $page->where('summary.month', '2027-01')->where('summary.open', 1));
    }

    public function test_the_summary_holds_counts_only(): void
    {
        $this->reservation(['guest_name' => 'Tamu Rahasia']);

        $response = $this->actingAs($this->staff)->get(route('dine-reservations.index'))->assertOk();
        $summary = $response->viewData('page')['props']['summary'];

        $this->assertSame(['month', 'made', 'open', 'arrived', 'cancelled', 'no_show', 'no_show_percent'], array_keys($summary));
        $this->assertStringNotContainsString('Tamu Rahasia', json_encode($summary));
        $this->assertStringNotContainsString('812-0000-1111', json_encode($summary));
    }

    /** The month summary of the shown page date, straight from the service. */
    private function monthSummary(string $date = '2026-12-10'): array
    {
        return app(DineReservationService::class)->monthSummary($this->outlet, Carbon::parse($date));
    }

    /** The row of $table must be locked by key before $then runs: that order is what queues two writers. */
    private function assertLockedBefore(callable $act, string $table, string $then): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $act();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // SQLite quotes names with ", MySQL (the CI backend job) with `: match both.
        $first = fn (string $pattern) => collect($queries)->search(fn (string $sql) => (bool) preg_match($pattern, $sql));
        $lock = $first('/^select \* from [`"]'.$table.'[`"] where [`"]'.$table.'[`"]\.[`"]id[`"] = \?/');
        $read = $first('/'.$then.'/');

        $this->assertIsInt($lock);
        $this->assertIsInt($read);
        $this->assertLessThan($read, $lock);
        // SQLite renders no lock clause: only the MySQL job can show it.
        if (DB::getDriverName() === 'mysql') {
            $this->assertStringContainsString('for update', strtolower($queries[$lock]));
        }
    }

    private function outletNamed(string $code): Outlet
    {
        return Outlet::create(['code' => $code, 'name' => $code, 'is_active' => true]);
    }

    /** @param array<int, string> $permissions */
    private function userAt(Outlet $outlet, array $permissions): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        foreach ($permissions as $name) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']));
        }
        $user->outlets()->attach($outlet->id);

        return $user;
    }

    /** Without an outlet the table has no area, so every outlet shares it. */
    private function tableAt(?Outlet $outlet, string $name, bool $active = true): DiningTable
    {
        $area = $outlet ? DineArea::create(['name' => "Area {$name}", 'outlet_id' => $outlet->id, 'sort_order' => 0, 'is_active' => true]) : null;

        return DiningTable::create(['dine_area_id' => $area?->id, 'name' => $name, 'capacity' => 4, 'sort_order' => 0, 'is_active' => $active]);
    }

    private function reservation(array $attributes = []): DineReservation
    {
        return DineReservation::create($attributes + [
            'outlet_id' => $this->outlet->id, 'dine_table_id' => $this->table->id, 'guest_name' => 'Budi', 'phone' => self::PHONE,
            'party_size' => 4, 'reserved_at' => '2026-12-10 19:00:00', 'note' => 'Dekat jendela', 'status' => 'booked',
        ]);
    }

    /** The posted form; without changes it matches what reservation() stores. */
    private function form(array $changes): array
    {
        return $changes + [
            'guest_name' => 'Budi', 'phone' => self::PHONE, 'party_size' => 4, 'reserved_date' => '2026-12-10',
            'reserved_time' => '19:00', 'dine_table_id' => $this->table->id, 'note' => 'Dekat jendela',
        ];
    }

    private function book(array $changes = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->post(route('dine-reservations.store'), $this->form($changes));
    }

    private function change(DineReservation $reservation, array $changes, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->patch(route('dine-reservations.update', $reservation), $this->form($changes));
    }

    private function mark(DineReservation $reservation, string $status, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->staff)->post(route('dine-reservations.mark', $reservation), ['status' => $status]);
    }
}
