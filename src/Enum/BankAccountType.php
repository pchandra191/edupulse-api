<?php
declare(strict_types=1);

namespace App\Enum;

enum BankAccountType: string
{
    case Savings = 'SAVINGS';
    case Current = 'CURRENT';
    case Salary = 'SALARY';
    case FixedDeposit = 'FIXED_DEPOSIT';
    case RecurringDeposit = 'RECURRING_DEPOSIT';
    case Other = 'OTHER';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
