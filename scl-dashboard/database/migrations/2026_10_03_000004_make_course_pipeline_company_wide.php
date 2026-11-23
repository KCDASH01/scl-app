<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One Course Pipeline for the company. Extra per-branch copies are merged away.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pipelines') || ! Schema::hasTable('stages')) {
            return;
        }

        $pipelines = DB::table('pipelines')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        if ($pipelines->isEmpty()) {
            return;
        }

        $keeper = $pipelines->first();
        $keeperStages = DB::table('stages')
            ->where('pipeline_id', $keeper->id)
            ->orderBy('sort_order')
            ->get();

        foreach ($pipelines->skip(1) as $extra) {
            $extraStages = DB::table('stages')
                ->where('pipeline_id', $extra->id)
                ->orderBy('sort_order')
                ->get();

            foreach ($extraStages as $extraStage) {
                $target = $keeperStages->firstWhere('name', $extraStage->name)
                    ?? $keeperStages->first(fn ($stage) => (int) $stage->is_won === (int) $extraStage->is_won
                        && (int) $stage->is_lost === (int) $extraStage->is_lost
                        && (int) $stage->is_won + (int) $stage->is_lost === 1)
                    ?? $keeperStages->firstWhere('sort_order', $extraStage->sort_order)
                    ?? $keeperStages->first();

                if (! $target) {
                    continue;
                }

                if (Schema::hasTable('deals')) {
                    DB::table('deals')
                        ->where('pipeline_id', $extra->id)
                        ->where('stage_id', $extraStage->id)
                        ->update([
                            'pipeline_id' => $keeper->id,
                            'stage_id'    => $target->id,
                            'updated_at'  => now(),
                        ]);
                }

                if (Schema::hasTable('leads')) {
                    DB::table('leads')
                        ->where('pipeline_id', $extra->id)
                        ->where('stage_id', $extraStage->id)
                        ->update([
                            'pipeline_id' => $keeper->id,
                            'stage_id'    => $target->id,
                            'updated_at'  => now(),
                        ]);
                }
            }

            if (Schema::hasTable('deals')) {
                DB::table('deals')->where('pipeline_id', $extra->id)->update([
                    'pipeline_id' => $keeper->id,
                    'updated_at'  => now(),
                ]);
            }

            if (Schema::hasTable('leads')) {
                DB::table('leads')->where('pipeline_id', $extra->id)->update([
                    'pipeline_id' => $keeper->id,
                    'updated_at'  => now(),
                ]);
            }

            DB::table('stages')->where('pipeline_id', $extra->id)->delete();
            DB::table('pipelines')->where('id', $extra->id)->update([
                'is_default' => false,
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('pipelines')->update(['is_default' => false]);
        DB::table('pipelines')->where('id', $keeper->id)->update([
            'name'        => 'Course Pipeline',
            'description' => 'Default student enquiry-to-enrollment pipeline',
            'is_default'  => true,
            'deleted_at'  => null,
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        // Extra per-branch pipelines cannot be reconstructed safely.
    }
};
