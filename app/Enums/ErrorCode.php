<?php

namespace App\Enums;

/** 06 §1.3 のエラーの code */
enum ErrorCode: string
{
    case Validation = 'VALIDATION';
    case Forbidden = 'FORBIDDEN';
    case StoreSuspended = 'STORE_SUSPENDED';
    case AccountDisabled = 'ACCOUNT_DISABLED';
    case OutOfStock = 'OUT_OF_STOCK';
    case AlreadyCancelled = 'ALREADY_CANCELLED';
    case TotalMismatch = 'TOTAL_MISMATCH';
    case ItemUnavailable = 'ITEM_UNAVAILABLE';
    case CancelNotAllowed = 'CANCEL_NOT_ALLOWED';
    case ImportInvalid = 'IMPORT_INVALID';
    case TooManyAttempts = 'TOO_MANY_ATTEMPTS';
}
