<?php

namespace App\Enums;

enum TaxMode: string
{
    case Inclusive = 'inclusive';
    case Exclusive = 'exclusive';
    case Account = 'account';
}
