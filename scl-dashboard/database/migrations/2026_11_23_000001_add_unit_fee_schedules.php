<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->json('period_prices')->nullable();
        });
        Schema::table('admissions', function (Blueprint $table) {
            $table->json('fee_schedule')->nullable();
        });
        Schema::table('deals', function (Blueprint $table) {
            $table->json('fee_periods')->nullable();
            $table->string('fee_unit', 16)->nullable();
            $table->unsignedInteger('fee_duration')->nullable();
        });
        Schema::table('deal_payments', function (Blueprint $table) {
            $table->json('allocations')->nullable();
            $table->json('receipt_snapshot')->nullable();
        });
        Schema::create('fee_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('deal_payments')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->text('reason')->nullable();
            $table->json('before');
            $table->json('after');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_audits');
        Schema::table('deal_payments', fn (Blueprint $table) => $table->dropColumn(['allocations', 'receipt_snapshot']));
        Schema::table('deals', fn (Blueprint $table) => $table->dropColumn(['fee_periods', 'fee_unit', 'fee_duration']));
        Schema::table('admissions', fn (Blueprint $table) => $table->dropColumn('fee_schedule'));
        Schema::table('courses', fn (Blueprint $table) => $table->dropColumn('period_prices'));
    }
};
