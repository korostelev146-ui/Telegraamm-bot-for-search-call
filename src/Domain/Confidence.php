<?php

declare(strict_types=1);

namespace App\Domain;

enum Confidence: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
}
