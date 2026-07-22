<?php

namespace App\Validator\Constraints;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class PasswordPolicy extends \Symfony\Component\Validator\Constraint
{
    public string $message = 'The password does not meet the security requirements.';
    public string $messageMinLength = 'Password must be at least {{ limit }} characters long.';
    public string $messageUppercase = 'Password must contain at least one uppercase letter.';
    public string $messageLowercase = 'Password must contain at least one lowercase letter.';
    public string $messageNumber = 'Password must contain at least one number.';
    public string $messageSpecialChar = 'Password must contain at least one special character.';
}
