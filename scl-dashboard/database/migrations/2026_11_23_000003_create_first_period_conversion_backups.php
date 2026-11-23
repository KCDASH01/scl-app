<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('first_period_conversion_backups', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 32);
            $table->unsignedBigInteger('record_id');
            $table->json('original_record');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['entity', 'record_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('first_period_conversion_backups')->exists()) {
            throw new RuntimeException('Fee conversion backups contain financial history. Restore a reviewed database backup instead of dropping them.');
        }
        Schema::dropIfExists('first_period_conversion_backups');
    }
};
