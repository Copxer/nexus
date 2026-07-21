<?php

namespace App\Enums;

enum PullRequestRiskAssessmentStatus: string
{
    case Pending = 'pending';
    case Scored = 'scored';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
