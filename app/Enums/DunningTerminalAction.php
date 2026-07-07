<?php

namespace App\Enums;

/**
 * What happens when dunning retries are exhausted for a past-due subscription.
 */
enum DunningTerminalAction: string
{
    case Cancel = 'cancel';
    case Pause = 'pause';
    case LeaveOpen = 'leave_open';
}
