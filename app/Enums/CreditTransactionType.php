<?php

namespace App\Enums;

enum CreditTransactionType: string
{
    /** Credits bought by the user (positive). */
    case Purchase = 'purchase';
    /** Maximum possible price reserved at submission (negative). */
    case Hold = 'hold';
    /** The hold given back once the generation finishes either way (positive). */
    case Release = 'release';
    /** Actual price of a succeeded generation (negative). */
    case Charge = 'charge';
}
