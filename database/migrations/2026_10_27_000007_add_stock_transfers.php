<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('destination_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('staff_name');
            $table->string('reference', 30)->unique();
            $table->string('status', 20)->default('in_transit');
            $table->unsignedInteger('version')->default(0);
            $table->json('source_snapshot');
            $table->json('destination_snapshot');
            $table->string('timezone', 64);
            $table->string('notes', 500);
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->unique(['source_branch_id', 'idempotency_key']);
            $table->timestamp('dispatched_at');
            $table->timestamp('completed_at')->nullable();
            $table->index(['source_branch_id', 'status']);
            $table->index(['destination_branch_id', 'status']);
            $table->timestamps();
        });
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('destination_product_id')->constrained('products')->restrictOnDelete();
            $table->string('source_name');
            $table->string('destination_name');
            $table->string('sku', 60);
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('received_quantity')->default(0);
            $table->unsignedInteger('damaged_quantity')->default(0);
            $table->unsignedInteger('missing_quantity')->default(0);
            $table->unique(['stock_transfer_id', 'source_product_id'], 'transfer_source_product_unique');
            $table->unique(['stock_transfer_id', 'destination_product_id'], 'transfer_destination_product_unique');
        });
        Schema::create('transfer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('staff_name');
            $table->string('reference', 30)->unique();
            $table->string('delivery_reference', 100);
            $table->string('notes', 500);
            $table->string('timezone', 64);
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->unique(['branch_id', 'idempotency_key']);
            $table->timestamp('received_at');
            $table->timestamps();
        });
        Schema::create('transfer_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_receipt_id')->constrained()->restrictOnDelete();
            $table->foreignId('stock_transfer_item_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('received_quantity');
            $table->unsignedInteger('damaged_quantity');
            $table->unsignedInteger('missing_quantity');
            $table->unique(['transfer_receipt_id', 'stock_transfer_item_id'], 'transfer_receipt_line_unique');
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('stock_transfer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('transfer_receipt_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transfer_receipt_id');
            $table->dropConstrainedForeignId('stock_transfer_id');
        });
        Schema::dropIfExists('transfer_receipt_items');
        Schema::dropIfExists('transfer_receipts');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
