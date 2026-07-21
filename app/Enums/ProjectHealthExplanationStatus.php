<?php

namespace App\Enums;

enum ProjectHealthExplanationStatus: string
{
    case Pending = 'pending';
    case Explained = 'explained';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
