<?php

namespace App\Enums;

enum PriceType: string
{
    case Recurring = 'recurring';
    case OneTime = 'one_time';
}
