<?php

namespace App\Enums;

enum DraftShiftWarningCode: string
{
    case SameStoreDuplicate = 'same_store_duplicate';
    case CrossStoreDuplicate = 'cross_store_duplicate';
    case StaffingShortage = 'staffing_shortage';
    case StaffingExcess = 'staffing_excess';
    case StaffingRequirementMissing = 'staffing_requirement_missing';
}
