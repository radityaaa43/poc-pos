<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            // Unique: the guard that makes intake idempotent (webhook retries, paid twice).
            $table->foreignId('transaction_detail_id')->unique()->constrained('transaction_details')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('warehouse_id')->constrained();
            $table->unsignedInteger('qty');
            // Copied from the dine-in order line ("no sugar"); POS lines have none.
            $table->string('note', 255)->nullable();
            // A string, not an enum: later states (cancelled, not_collected) stay additive.
            $table->string('status', 20)->default('accepted');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('preparing_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('ready_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('served_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['warehouse_id', 'status', 'accepted_at']);
        });

        // Release data: paid dine orders already in the kitchen get their flagged lines, at their current
        // status and without times (history before release has no kitchen timing; the speed report skips them).
        $rows = DB::table('dine_orders')
            ->join('transactions', 'transactions.id', '=', 'dine_orders.transaction_id')
            ->join('transaction_details', 'transaction_details.transaction_id', '=', 'transactions.id')
            ->join('products', 'products.id', '=', 'transaction_details.product_id')
            ->whereIn('dine_orders.status', ['accepted', 'preparing', 'ready'])
            ->where('transactions.payment_status', 'paid')
            ->whereNotNull('transactions.warehouse_id')
            ->where('products.needs_preparation', true)
            ->orderBy('transaction_details.id')
            ->get([
                'dine_orders.id as dine_order_id',
                'dine_orders.status',
                'transactions.id as transaction_id',
                'transactions.warehouse_id',
                'transaction_details.id as detail_id',
                'transaction_details.product_id',
                'transaction_details.unit_id',
                'transaction_details.qty',
            ]);

        // The few in-progress orders at release: read each order's line notes once, matched to the sale lines
        // the way the intake does (same product and unit, first line not yet used).
        $notes = [];
        foreach ($rows->groupBy('dine_order_id') as $orderId => $orderRows) {
            $notes[$orderId] = DB::table('dine_order_items')->where('dine_order_id', $orderId)->orderBy('id')->get(['product_id', 'unit_id', 'note'])->all();
        }

        DB::transaction(function () use ($rows, $notes) {
            $now = now();
            foreach ($rows as $row) {
                $note = null;
                foreach ($notes[$row->dine_order_id] as $i => $item) {
                    if ($item->product_id == $row->product_id && $item->unit_id == $row->unit_id) {
                        $note = $item->note;
                        unset($notes[$row->dine_order_id][$i]);
                        break;
                    }
                }

                DB::table('kitchen_items')->insert([
                    'transaction_id' => $row->transaction_id,
                    'transaction_detail_id' => $row->detail_id,
                    'product_id' => $row->product_id,
                    'warehouse_id' => $row->warehouse_id,
                    'qty' => $row->qty,
                    'note' => $note, // a line note is validated to 255 characters, the column's size
                    'status' => $row->status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_items');
    }
};
