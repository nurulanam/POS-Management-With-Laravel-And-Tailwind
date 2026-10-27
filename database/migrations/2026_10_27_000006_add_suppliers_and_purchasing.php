<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('code', 60);
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->unique(['branch_id', 'code']);
            $table->timestamps();
        });
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->unique(['branch_id', 'idempotency_key']);
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('version')->default(0);
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->json('supplier_snapshot');
            $table->json('branch_snapshot');
            $table->string('staff_name');
            $table->string('supplier_reference', 100)->nullable();
            $table->date('expected_on')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('ordered_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('closer_name')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->uuid('close_key')->nullable();
            $table->char('close_hash', 64)->nullable();
            $table->unique(['branch_id', 'close_key']);
            $table->index(['branch_id', 'ordered_at']);
            $table->timestamps();
        });
        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku', 60);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedBigInteger('unit_cost_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unique(['purchase_order_id', 'product_id']);
        });
        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('delivery_reference', 100);
            $table->string('notes', 500);
            $table->string('staff_name');
            $table->string('timezone', 64);
            $table->char('currency', 3);
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->unique(['branch_id', 'idempotency_key']);
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('received_at');
            $table->index(['branch_id', 'received_at']);
            $table->timestamps();
        });
        Schema::create('purchase_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('total_minor');
            $table->unique(['purchase_receipt_id', 'purchase_order_item_id'], 'receipt_order_line_unique');
        });
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->foreignId('purchase_receipt_id')->nullable()->constrained()->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_receipt_id'));
        Schema::dropIfExists('purchase_receipt_items');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};
