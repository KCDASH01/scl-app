<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->enum('status', [
                'new', 'contacted', 'ringing', 'important', 'converted', 'lost',
                'followup', 'meeting', 'visited', 'not_interested',
            ])->default('new')->change();
        });
    }

    public function down(): void
    {
        DB::table('leads')->where('status', 'visited')->update(['status' => 'meeting']);

        Schema::table('leads', function (Blueprint $table) {
            $table->enum('status', [
                'new', 'contacted', 'ringing', 'important', 'converted', 'lost',
                'followup', 'meeting', 'not_interested',
            ])->default('new')->change();
        });
    }
};
