<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerCampaign;
use App\Models\CustomerCampaignLog;
use App\Models\CustomerSegment;
use App\Models\CustomerVoucher;
use App\Models\Outlet;
use App\Models\PriceList;
use App\Models\PricingRule;
use App\Models\PricingRuleBuyGetItem;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CustomerSegmentationService;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pricing and CRM scenarios (`crm.*`). Rules and price lists always target products the scenario
 * creates itself, so they never change the cart of another feature's step in the shared database.
 */
class PricingCrm
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Opens a shift for the cashier and empties the cashier's cart (the POS cart is stored server side).
            'crm.pos-ready' => function (array $options): array {
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();

                CashierShift::open()->where('user_id', $cashier->id)
                    ->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);
                Cart::where('cashier_id', $cashier->id)->delete();

                $shift = app(CashierShiftService::class)->openShift($cashier, $cashier, 100000, null, $warehouse->id);

                return ['shift_id' => $shift->id];
            },

            // A product with its own price (--arg=title, price, stock).
            'crm.product' => fn (array $options): array => self::product($options),

            // A customer (--arg=name, member=1, points, tier, spent, region=1 for the crm.regions chain).
            'crm.customer' => function (array $options): array {
                $member = ($options['member'] ?? '0') === '1';
                $customer = Customer::create([
                    'name' => $options['name'] ?? 'Pelanggan '.Str::upper(Str::random(5)),
                    'no_telp' => '08'.random_int(1000000000, 9999999999),
                    'address' => 'Jl. Uji E2E',
                    'is_loyalty_member' => $member,
                    'member_code' => $member ? 'MBR-'.Str::upper(Str::random(8)) : null,
                    'loyalty_tier' => $options['tier'] ?? 'regular',
                    'loyalty_points' => (int) ($options['points'] ?? 0),
                    'loyalty_total_spent' => (int) ($options['spent'] ?? 0),
                    'loyalty_member_since' => $member ? now() : null,
                    ...(($options['region'] ?? '0') === '1' ? [
                        'province_id' => '11', 'province_name' => 'ACEH',
                        'regency_id' => '1101', 'regency_name' => 'KAB. ACEH SELATAN',
                        'district_id' => '110101', 'district_name' => 'BAKONGAN',
                        'village_id' => '1101012001', 'village_name' => 'KEUDE BAKONGAN',
                    ] : []),
                ]);

                return ['customer_id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->no_telp];
            },

            // Qty break on one product: --arg=name, product_id, min_qty, price (fixed unit price from min_qty on).
            'crm.qty-break-rule' => function (array $options): array {
                $rule = self::rule($options, PricingRule::KIND_QTY_BREAK, [
                    'target_type' => PricingRule::TARGET_PRODUCT,
                    'product_id' => (int) $options['product_id'],
                    'discount_type' => PricingRule::TYPE_FIXED_PRICE,
                    'discount_value' => 0,
                ]);
                $rule->qtyBreaks()->create([
                    'min_qty' => (int) $options['min_qty'],
                    'discount_type' => PricingRule::TYPE_FIXED_PRICE,
                    'discount_value' => (int) $options['price'],
                    'sort_order' => 0,
                ]);

                return ['rule_id' => $rule->id, 'name' => $rule->name];
            },

            // Bundle of two products at one price: --arg=name, product_a, product_b, price.
            'crm.bundle-rule' => function (array $options): array {
                $rule = self::rule($options, PricingRule::KIND_BUNDLE_PRICE, [
                    'target_type' => PricingRule::TARGET_ALL,
                    'discount_type' => PricingRule::TYPE_FIXED_PRICE,
                    'discount_value' => (int) $options['price'],
                ]);
                $rule->bundleItems()->createMany([
                    ['product_id' => (int) $options['product_a'], 'quantity' => 1, 'sort_order' => 0],
                    ['product_id' => (int) $options['product_b'], 'quantity' => 1, 'sort_order' => 1],
                ]);

                return ['rule_id' => $rule->id, 'name' => $rule->name];
            },

            // Buy one of --arg=buy_id, get one of --arg=get_id free (the reward is discounted to zero).
            'crm.buy-get-rule' => function (array $options): array {
                $rule = self::rule($options, PricingRule::KIND_BUY_X_GET_Y, [
                    'target_type' => PricingRule::TARGET_ALL,
                    'discount_type' => PricingRule::TYPE_FIXED_AMOUNT,
                    'discount_value' => 0,
                ]);
                $rule->buyGetItems()->createMany([
                    ['product_id' => (int) $options['buy_id'], 'role' => PricingRuleBuyGetItem::ROLE_BUY, 'quantity' => 1, 'sort_order' => 0],
                    ['product_id' => (int) $options['get_id'], 'role' => PricingRuleBuyGetItem::ROLE_GET, 'quantity' => 1, 'sort_order' => 1],
                ]);

                return ['rule_id' => $rule->id, 'name' => $rule->name];
            },

            // Percentage discount on one product (--arg=name, product_id, percent, scope=all|member|registered).
            'crm.standard-rule' => function (array $options): array {
                $rule = self::rule($options, PricingRule::KIND_STANDARD_DISCOUNT, [
                    'target_type' => PricingRule::TARGET_PRODUCT,
                    'product_id' => (int) $options['product_id'],
                    'discount_type' => PricingRule::TYPE_PERCENTAGE,
                    'discount_value' => (int) ($options['percent'] ?? 10),
                ]);

                return ['rule_id' => $rule->id, 'name' => $rule->name];
            },

            // A member-only price list holding --arg=product_id at --arg=price (the page requires a fresh password).
            'crm.price-list' => function (array $options): array {
                $list = PriceList::create([
                    'name' => $options['name'] ?? 'Harga '.Str::upper(Str::random(5)),
                    'slug' => 'e2e-'.Str::lower(Str::random(8)),
                    'customer_scope' => 'member',
                    'is_active' => true,
                    'priority' => time(), // only the highest-priority matching list is read, so the newest one must win over lists left by earlier steps
                ]);
                if (isset($options['product_id'])) {
                    $list->items()->create(['product_id' => (int) $options['product_id'], 'price' => (int) $options['price']]);
                }

                return ['price_list_id' => $list->id, 'name' => $list->name];
            },

            // A voucher for --arg=customer_id (code, name, type, value, min, active, expires=past|future).
            'crm.voucher' => function (array $options): array {
                $voucher = CustomerVoucher::create([
                    'customer_id' => (int) $options['customer_id'],
                    'code' => $options['code'] ?? 'VCR-'.Str::upper(Str::random(8)),
                    'name' => $options['name'] ?? 'Voucher E2E',
                    'discount_type' => $options['type'] ?? CustomerVoucher::TYPE_FIXED_AMOUNT,
                    'discount_value' => (int) ($options['value'] ?? 5000),
                    'minimum_order' => (int) ($options['min'] ?? 0),
                    'is_active' => ($options['active'] ?? '1') === '1',
                    'is_used' => false,
                    'expires_at' => match ($options['expires'] ?? null) {
                        'past' => now()->subDay(),
                        'future' => now()->addMonth(),
                        default => null,
                    },
                ]);

                return ['voucher_id' => $voucher->id, 'code' => $voucher->code, 'name' => $voucher->name];
            },

            // A manual segment (--arg=name, customer_ids=1,2 to fill it) so a step never touches the seeded auto segments.
            'crm.segment' => function (array $options): array {
                $name = $options['name'] ?? 'Segmen '.Str::upper(Str::random(5));
                $segment = CustomerSegment::create([
                    'name' => $name,
                    'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
                    'type' => CustomerSegment::TYPE_MANUAL,
                    'is_active' => true,
                ]);

                foreach (array_filter(explode(',', $options['customer_ids'] ?? '')) as $customerId) {
                    $customer = Customer::findOrFail((int) $customerId);
                    app(CustomerSegmentationService::class)->syncManualSegments($customer, [$segment->id]);
                }

                return ['segment_id' => $segment->id, 'name' => $segment->name];
            },

            // A campaign in the given status with one log per --arg=customer_ids (comma separated) in ready_to_send.
            'crm.campaign' => function (array $options): array {
                $campaign = CustomerCampaign::create([
                    'name' => $options['name'] ?? 'Campaign '.Str::upper(Str::random(5)),
                    'outlet_id' => Outlet::where('code', E2eSeeder::OUTLET_CODE)->value('id'),
                    'type' => $options['type'] ?? CustomerCampaign::TYPE_PROMO_BROADCAST,
                    'status' => $options['status'] ?? CustomerCampaign::STATUS_READY,
                    'channel' => CustomerCampaign::CHANNEL_WHATSAPP_LINK,
                    'audience_filters' => [],
                    'message_template' => 'Halo {{name}}',
                    'processed_at' => now(),
                ]);
                $logIds = [];
                foreach (array_filter(explode(',', $options['customer_ids'] ?? '')) as $customerId) {
                    $message = 'Halo dari e2e';
                    $logIds[] = $campaign->logs()->create([
                        'customer_id' => (int) $customerId,
                        'channel' => CustomerCampaign::CHANNEL_WHATSAPP_LINK,
                        'status' => CustomerCampaignLog::STATUS_READY_TO_SEND,
                        'payload' => ['message' => $message, 'whatsapp_url' => 'https://wa.me/?text='.urlencode($message)],
                    ])->id;
                }

                return ['campaign_id' => $campaign->id, 'name' => $campaign->name, 'log_ids' => $logIds];
            },

            // Real laravolt codes (province 2, city 4, district 6, village 10 digits, no dots).
            'crm.regions' => function (): array {
                $now = now();
                DB::table('indonesia_provinces')->updateOrInsert(['code' => '11'], ['name' => 'ACEH', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('indonesia_cities')->updateOrInsert(['code' => '1101'], ['province_code' => '11', 'name' => 'KAB. ACEH SELATAN', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('indonesia_districts')->updateOrInsert(['code' => '110101'], ['city_code' => '1101', 'name' => 'BAKONGAN', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('indonesia_villages')->updateOrInsert(['code' => '1101012001'], ['district_code' => '110101', 'name' => 'KEUDE BAKONGAN', 'created_at' => $now, 'updated_at' => $now]);

                return ['province' => 'ACEH', 'regency' => 'KAB. ACEH SELATAN', 'district' => 'BAKONGAN', 'village' => 'KEUDE BAKONGAN', 'village_code' => '1101012001'];
            },
        ];
    }

    /** @param array<string, string> $options */
    private static function product(array $options): array
    {
        $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
        $category = Category::where('name', 'E2E Kategori')->firstOrFail();
        $stock = (int) ($options['stock'] ?? 50);

        $product = DB::transaction(function () use ($options, $warehouse, $category, $stock) {
            $product = Product::create([
                'category_id' => $category->id,
                'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                'title' => $options['title'] ?? 'Produk CRM '.Str::upper(Str::random(5)),
                'buy_price' => 5000,
                'sell_price' => (int) ($options['price'] ?? 10000),
                'stock' => $stock,
                'tax_type' => 'exclusive',
                'tax_rate' => 0,
            ]);
            $warehouse->products()->attach($product->id, ['stock' => $stock]);

            return $product;
        });

        return ['product_id' => $product->id, 'title' => $product->title];
    }

    /** @param array<string, string> $options */
    private static function rule(array $options, string $kind, array $attributes): PricingRule
    {
        return PricingRule::create([
            'name' => $options['name'] ?? 'Promo '.Str::upper(Str::random(5)),
            'kind' => $kind,
            'is_active' => true,
            'priority' => 500,
            'customer_scope' => $options['scope'] ?? PricingRule::SCOPE_ALL,
            ...$attributes,
        ]);
    }
}
