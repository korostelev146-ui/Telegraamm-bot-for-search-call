<?php

declare(strict_types=1);

namespace App\Domain;

enum PhoneOrigin: string
{
    case STRUCTURED = 'structured';
    case TEXT = 'text';
}
