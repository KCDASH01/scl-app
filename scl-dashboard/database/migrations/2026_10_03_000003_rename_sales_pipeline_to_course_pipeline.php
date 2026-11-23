<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Existing branches still have Sales Pipeline / old sales stage names.
 * New branches already get Course Pipeline from PipelineSetupService.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pipelines')
            ->where('name', 'Sales Pipeline')
            ->update([
                'name'        => 'Course Pipeline',
                'description' => 'Default student enquiry-to-enrollment pipeline',
                'updated_at'  => now(),
            ]);

        $renames = [
            'New'           => 'New Enquiry',
            'New Lead'      => 'New Enquiry',
            'Qualified'     => 'Counseling',
            'Proposal'      => 'Ready to Join',
            'Proposal Sent' => 'Ready to Join',
            'Won'           => 'Enrolled',
            'Closed Won'    => 'Enrolled',
        ];

        foreach ($renames as $from => $to) {
            DB::table('stages')
                ->where('name', $from)
                ->update([
                    'name'       => $to,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('pipelines')
            ->where('name', 'Course Pipeline')
            ->update([
                'name'        => 'Sales Pipeline',
                'description' => 'Default sales pipeline',
                'updated_at'  => now(),
            ]);

        $renames = [
            'New Enquiry'   => 'New',
            'Counseling'    => 'Qualified',
            'Ready to Join' => 'Proposal',
            'Enrolled'      => 'Won',
        ];

        foreach ($renames as $from => $to) {
            DB::table('stages')
                ->where('name', $from)
                ->update([
                    'name'       => $to,
                    'updated_at' => now(),
                ]);
        }
    }
};
