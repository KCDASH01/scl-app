<?php

namespace Tests\Feature;

use App\Models\Deal;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeletedLeadVisibilityTest extends TestCase
{
    public function test_web_deal_totals_hide_deleted_leads_and_deals_without_changing_stored_history(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->timestamp('deleted_at')->nullable();
        });
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->nullable();
            $table->decimal('value', 12, 2);
            $table->timestamp('deleted_at')->nullable();
        });
        DB::table('leads')->insert([['id' => 1, 'deleted_at' => null], ['id' => 2, 'deleted_at' => now()]]);
        DB::table('deals')->insert([
            ['lead_id' => 1, 'value' => 100, 'deleted_at' => null],
            ['lead_id' => 2, 'value' => 200, 'deleted_at' => null],
            ['lead_id' => 1, 'value' => 300, 'deleted_at' => now()],
            ['lead_id' => null, 'value' => 50, 'deleted_at' => null],
        ]);
        Route::get('/_test/deal-visibility', fn () => response()->json(['count' => Deal::count(), 'value' => (float) Deal::sum('value')]));
        $this->getJson('/_test/deal-visibility')->assertOk()->assertJsonPath('count', 2)->assertJsonPath('value', 150);
        $this->assertSame(4, Deal::count());
        $this->assertSame(650.0, (float) DB::table('deals')->sum('value'));
    }
}
