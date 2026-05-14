<?php

declare(strict_types=1);

namespace App\Domain;

enum DealType: string
{
    case SALE = 'sale';
    case RENT = 'rent';
}
