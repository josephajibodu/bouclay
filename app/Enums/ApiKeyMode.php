<?php

namespace App\Enums;

enum ApiKeyMode: string
{
    case Test = 'test';
    case Live = 'live';
}
