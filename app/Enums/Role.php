<?php

namespace App\Enums;

/** 05 §5.1 */
enum Role: string
{
    case Admin = 'admin';
    case Owner = 'owner';
    case Staff = 'staff';
}
