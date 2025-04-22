<?php

namespace App\Enums\Accounting;

enum CreditNoteStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Closed = 'closed';
    case Applied = 'applied';
    case Partial = 'partial';
}
