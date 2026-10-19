<?php

declare(strict_types=1);

namespace Examples\Enums;

use EarthlingOllie\SystemOneClassification\Domain\Describe;

enum Department: string
{
    #[Describe('invoices, payments, refunds')]
    case Billing = 'billing';

    #[Describe('bugs, outages, system errors')]
    case Technical = 'technical';

    #[Describe('everything else')]
    case Other = 'other';
}
