<?php

namespace Tests\Unit\Services;

use App\Services\ShiftTypeMockService;
use App\Services\StaffShiftMockService;
use PHPUnit\Framework\TestCase;

class StaffShiftMockServiceTest extends TestCase
{
    public function test_store_and_personal_shifts_use_the_same_shift_type_id(): void
    {
        $shiftTypeService = new ShiftTypeMockService;
        $service = new StaffShiftMockService($shiftTypeService);
        $idsByCode = [];

        foreach ($service->stores() as $store) {
            foreach ($store['staff'] as $staff) {
                foreach ($staff['shifts'] as $shift) {
                    $shiftType = $shift['shift_type'];
                    $idsByCode[$shiftType['code']][] = $shiftType['id'];
                }
            }
        }

        foreach ($service->personalShifts() as $dayShifts) {
            foreach ($dayShifts as $shift) {
                $shiftType = $shift['shift_type'];
                $idsByCode[$shiftType['code']][] = $shiftType['id'];
            }
        }

        $this->assertArrayHasKey('C', $idsByCode);
        $this->assertSame([3], array_values(array_unique($idsByCode['C'])));
    }
}
