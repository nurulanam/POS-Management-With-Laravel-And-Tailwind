<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->index(['branch_id', 'paid_at'], 'sales_branch_reporting_date'));
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->index(['branch_id', 'created_at'], 'movements_branch_reporting_date'));
        Schema::table('products', fn (Blueprint $table) => $table->index(['branch_id', 'is_active', 'stock'], 'products_branch_low_stock'));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_branch_low_stock'));
        Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropIndex('movements_branch_reporting_date'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropIndex('sales_branch_reporting_date'));
    }
};
