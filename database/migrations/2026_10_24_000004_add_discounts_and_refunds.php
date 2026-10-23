<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('discount_minor')->default(0);
            $table->string('discount_type', 10)->default('none');
            $table->string('discount_value', 20)->nullable();
            $table->string('discount_reason', 500)->nullable();
            $table->foreignId('discount_authorized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('discount_authorizer_name')->nullable();
        });
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->string('reason', 500);
            $table->string('staff_name');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->timestamp('refunded_at');
            $table->timestamps();
            $table->unique(['branch_id', 'idempotency_key']);
            $table->index(['branch_id', 'refunded_at']);
        });
        Schema::create('refund_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->index('sale_item_id');
            $table->unsignedInteger('quantity');
            $table->boolean('restocked');
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('tax_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unique(['refund_id', 'sale_item_id']);
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('refund_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('refund_id'));
        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_authorized_by');
            $table->dropColumn(['discount_minor', 'discount_type', 'discount_value', 'discount_reason', 'discount_authorizer_name']);
        });
    }
};
