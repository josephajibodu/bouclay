<?php

namespace App\Enums;

enum ApiKeyKind: string
{
    case Publishable = 'publishable';
    case Secret = 'secret';
}
