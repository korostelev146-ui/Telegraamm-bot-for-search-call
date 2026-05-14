<?php

declare(strict_types=1);

namespace App\Domain;

enum Classification: string
{
    case OWNER = 'owner';
    case REALTOR = 'realtor';
    case UNKNOWN = 'unknown';
}
