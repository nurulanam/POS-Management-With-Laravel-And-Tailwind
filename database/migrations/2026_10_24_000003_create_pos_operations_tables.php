<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku', 60);
            $table->string('barcode', 80)->nullable();
            $table->string('category', 60)->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->unsignedBigInteger('stock')->default(0);
            $table->unsignedInteger('reorder_level')->default(5);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'sku']);
            $table->unique(['branch_id', 'barcode']);
            $table->index(['branch_id', 'is_active']);
        });
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['branch_id', 'is_active']);
        });
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reference', 30)->unique();
            $table->uuid('idempotency_key');
            $table->char('request_hash', 64);
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->unsignedInteger('tax_basis_points');
            foreach (['subtotal_minor', 'tax_minor', 'total_minor', 'tendered_minor', 'change_minor'] as $column) {
                $table->unsignedBigInteger($column);
            }
            $table->string('payment_method', 20)->default('cash');
            $table->json('branch_snapshot');
            $table->json('customer_snapshot')->nullable();
            $table->string('cashier_name');
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->unique(['branch_id', 'idempotency_key']);
            $table->index(['branch_id', 'user_id', 'paid_at']);
        });
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sku', 60);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price_minor');
            $table->unsignedBigInteger('line_total_minor');
        });
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->uuid('request_key')->nullable();
            $table->bigInteger('delta');
            $table->unsignedBigInteger('balance');
            $table->string('reason', 500);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['branch_id', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('products');
    }
};
