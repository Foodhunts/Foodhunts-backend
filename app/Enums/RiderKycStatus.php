<?php

namespace App\Enums;

enum RiderKycStatus: string
{
    case NotStarted = 'not_started';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
