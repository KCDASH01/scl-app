<?php

namespace App\Services;

use App\Models\Pipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PipelineSetupService
{
    public function createDefaultPipeline(int $branchId): Pipeline
    {
        $existing = Pipeline::query()->where('is_default', true)->first()
            ?? Pipeline::query()->orderBy('id')->first();

        if ($existing) {
            if (! $existing->is_default) {
                Pipeline::query()->update(['is_default' => false]);
                $existing->update(['is_default' => true]);
            }

            return $existing->load('stages');
        }

        Pipeline::query()->update(['is_default' => false]);

        $pipeline = new Pipeline([
            'branch_id'       => $branchId,
            'name'            => 'Course Pipeline',
            'description'     => 'Default student enquiry-to-enrollment pipeline',
            'is_default'      => true,
            'sort_order'      => 0,
        ]);

        if (Schema::hasColumn('pipelines', 'organization_id') && ! $pipeline->getAttribute('organization_id')) {
            $orgId = DB::table('organizations')->orderBy('id')->value('id');
            if (! $orgId) {
                $orgId = DB::table('organizations')->insertGetId([
                    'name'       => 'Acme Ltd',
                    'slug'       => 'acme-ltd',
                    'email'      => 'ops@acme.test',
                    'timezone'   => 'Asia/Kolkata',
                    'is_active'  => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $pipeline->setAttribute('organization_id', $orgId);
        }

        $pipeline->save();

        $stages = [
            ['name' => 'New Enquiry',   'color' => '#6366f1', 'sort_order' => 0, 'probability' => 10,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Contacted',     'color' => '#3b82f6', 'sort_order' => 1, 'probability' => 25,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Counseling',    'color' => '#f59e0b', 'sort_order' => 2, 'probability' => 50,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Ready to Join', 'color' => '#f97316', 'sort_order' => 3, 'probability' => 70,  'is_won' => false, 'is_lost' => false],
            ['name' => 'Enrolled',      'color' => '#22c55e', 'sort_order' => 4, 'probability' => 100, 'is_won' => true,  'is_lost' => false],
            ['name' => 'Lost',          'color' => '#ef4444', 'sort_order' => 5, 'probability' => 0,   'is_won' => false, 'is_lost' => true],
        ];

        foreach ($stages as $stage) {
            $model = $pipeline->stages()->make($stage);
            if (Schema::hasColumn('stages', 'organization_id')) {
                $model->setAttribute('organization_id', $pipeline->getAttribute('organization_id'));
            }
            $model->save();
        }

        return $pipeline;
    }
}
