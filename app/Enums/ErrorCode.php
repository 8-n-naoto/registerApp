<?php

namespace App\Enums;

/** 06 §1.3・12 §5.18 のエラーの code */
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

    // 12 §5.18（注文）
    case OrderNotAccepting = 'ORDER_NOT_ACCEPTING';
    case OrderStateConflict = 'ORDER_STATE_CONFLICT';
    case OrderAlreadyPaid = 'ORDER_ALREADY_PAID';
    case TableHasUnpaidOrders = 'TABLE_HAS_UNPAID_ORDERS';
    case OrderLimitExceeded = 'ORDER_LIMIT_EXCEEDED';

    // 13 §5（勤怠）
    case AttendanceOverlap = 'ATTENDANCE_OVERLAP';
    case AttendanceState = 'ATTENDANCE_STATE';
    case ShiftOverlap = 'SHIFT_OVERLAP';
    case ShiftRequestClosed = 'SHIFT_REQUEST_CLOSED';
    case OperatorUnavailable = 'OPERATOR_UNAVAILABLE';
}
