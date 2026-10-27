<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->boolean('cash_tracking_enabled')->default(false);
            $table->timestamp('cash_tracking_enabled_at')->nullable();
            $table->foreignId('cash_tracking_enabled_by')->nullable()->constrained('users')->restrictOnDelete();
        });
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            // NULL after closing permits history, while enforcing one open till per owner/branch.
            $table->foreignId('active_owner_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['branch_id', 'active_owner_id']);
            $table->string('reference', 30)->unique();
            $table->string('owner_name');
            $table->string('branch_name');
            $table->char('currency', 3);
            $table->string('timezone', 64);
            $table->unsignedBigInteger('opening_minor');
            $table->unsignedBigInteger('expected_minor');
            $table->unsignedBigInteger('version')->default(0);
            $table->uuid('opening_key');
            $table->char('opening_hash', 64);
            $table->unique(['branch_id', 'opening_key']);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('closer_name')->nullable();
            $table->unsignedBigInteger('counted_minor')->nullable();
            $table->bigInteger('variance_minor')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->uuid('close_key')->nullable();
            $table->char('close_hash', 64)->nullable();
            $table->unique(['branch_id', 'close_key']);
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('reviewer_name')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->uuid('review_key')->nullable();
            $table->char('review_hash', 64)->nullable();
            $table->unique(['branch_id', 'review_key']);
            $table->index(['branch_id', 'opened_at']);
            $table->index(['branch_id', 'closed_at']);
            $table->timestamps();
        });
        foreach (['sales', 'refunds'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete());
        }
        Schema::create('cash_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('staff_name');
            $table->string('kind', 10);
            $table->bigInteger('delta_minor');
            $table->unsignedBigInteger('balance_minor');
            $table->string('reason', 500);
            $table->foreignId('sale_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('refund_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->unique(['branch_id', 'idempotency_key']);
            $table->index(['cash_session_id', 'id']);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_entries');
        foreach (['sales', 'refunds'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_session_id'));
        }
        Schema::dropIfExists('cash_sessions');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cash_tracking_enabled_by');
            $table->dropColumn(['cash_tracking_enabled', 'cash_tracking_enabled_at']);
        });
    }
};
