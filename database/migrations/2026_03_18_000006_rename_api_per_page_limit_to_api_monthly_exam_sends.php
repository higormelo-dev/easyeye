<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    public function up(): void
    {
        DB::table('plan_features')
            ->where('feature', 'api_per_page_limit')
            ->update(['feature' => 'api_monthly_exam_sends']);

        DB::table('feature_usages')
            ->where('feature', 'api_per_page_limit')
            ->update(['feature' => 'api_monthly_exam_sends']);
    }

    public function down(): void
    {
        DB::table('plan_features')
            ->where('feature', 'api_monthly_exam_sends')
            ->update(['feature' => 'api_per_page_limit']);

        DB::table('feature_usages')
            ->where('feature', 'api_monthly_exam_sends')
            ->update(['feature' => 'api_per_page_limit']);
    }
};
