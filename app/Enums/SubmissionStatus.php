<?php

namespace App\Enums;

/**
 * A submission is answered before its files reach Drive — the sender must
 * not hold a phone connection open through the upload. So "received" and
 * "stored" are different facts, and the screen says which one it means.
 */
enum SubmissionStatus: string
{
    case Uploading = 'uploading';
    case Stored = 'stored';
    case Failed = 'failed';
}
