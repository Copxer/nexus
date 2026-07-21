<?php

namespace App\Enums;

enum DailyBriefingStatus: string
{
    case Pending = 'pending';
    case Generated = 'generated';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
