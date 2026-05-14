<?php

declare(strict_types=1);

namespace App\Domain;

enum Source: string
{
    case SREALITY = 'sreality';
    case BEZREALITKY = 'bezrealitky';
}
