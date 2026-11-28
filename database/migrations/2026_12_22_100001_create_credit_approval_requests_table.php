<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_approval_requests', function (Blueprint $table) {
            $table->id();
            // Restricted like a customer's sales: the record of who was excepted keeps its customer.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('cashier_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->string('hold_id', 40)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('reason_code', 30);
            $table->unsignedBigInteger('remaining_limit');
            // What the checkout needs again at approval; the amounts are computed again from the cart.
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('transaction_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_approval_requests');
    }
};
