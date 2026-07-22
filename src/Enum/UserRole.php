<?php

namespace App\Enum;

enum UserRole: string
{
    case Student = 'ROLE_STUDENT';
    case Teacher = 'ROLE_TEACHER';
    case Institute = 'ROLE_INSTITUTE';
    case Admin = 'ROLE_ADMIN';
}
