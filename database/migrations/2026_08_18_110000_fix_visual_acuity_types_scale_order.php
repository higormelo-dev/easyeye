<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Scale values were assigned out of clinical order when the intermediate
     * 20/1xx-3xx acuities were added (e.g. 20/150 = 10 came before 20/125 = 17).
     * The panel orders the "A/V sem/com correção" dropdowns by this column,
     * so it needs to strictly ascend from best to worst acuity.
     */
    private function map(): array
    {
        return [
            'NO TEST'     => 0,
            '20/20'       => 1,
            '20/25'       => 2,
            '20/30'       => 3,
            '20/40'       => 4,
            '20/50'       => 5,
            '20/60'       => 6,
            '20/70'       => 7,
            '20/80'       => 8,
            '20/100'      => 9,
            '20/125'      => 10,
            '20/150'      => 11,
            '20/175'      => 12,
            '20/200'      => 13,
            '20/225'      => 14,
            '20/250'      => 15,
            '20/275'      => 16,
            '20/300'      => 17,
            '20/325'      => 18,
            '20/350'      => 19,
            '20/375'      => 20,
            '20/400'      => 21,
            'CONTA DEDOS' => 22,
            'VULTOS'      => 23,
            'PL'          => 24,
            'SPL'         => 25,
        ];
    }

    private function previousMap(): array
    {
        return [
            'NO TEST'     => 0,
            '20/20'       => 1,
            '20/25'       => 2,
            '20/30'       => 3,
            '20/40'       => 4,
            '20/50'       => 5,
            '20/60'       => 6,
            '20/70'       => 7,
            '20/80'       => 8,
            '20/100'      => 9,
            '20/125'      => 17,
            '20/150'      => 10,
            '20/175'      => 18,
            '20/200'      => 11,
            '20/225'      => 19,
            '20/250'      => 20,
            '20/275'      => 21,
            '20/300'      => 22,
            '20/325'      => 23,
            '20/350'      => 24,
            '20/375'      => 25,
            '20/400'      => 12,
            'CONTA DEDOS' => 13,
            'VULTOS'      => 14,
            'PL'          => 15,
            'SPL'         => 16,
        ];
    }

    public function up(): void
    {
        foreach ($this->map() as $name => $scale) {
            DB::table('visual_acuity_types')->where('name', $name)->update(['scale' => $scale]);
        }
    }

    public function down(): void
    {
        foreach ($this->previousMap() as $name => $scale) {
            DB::table('visual_acuity_types')->where('name', $name)->update(['scale' => $scale]);
        }
    }
};
