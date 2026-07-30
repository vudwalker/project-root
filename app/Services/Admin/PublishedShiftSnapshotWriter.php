<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;

class PublishedShiftSnapshotWriter
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function replace(int $shiftScheduleId, array $rows): void
    {
        DB::table('published_shifts')
            ->where('shift_schedule_id', $shiftScheduleId)
            ->delete();

        $this->insertRows($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    protected function insertRows(array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('published_shifts')->insert($chunk);
        }
    }
}
