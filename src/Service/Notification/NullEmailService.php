<?php

namespace App\Service\Notification;

use App\Entity\EmailVerificationToken;
use App\Entity\User;

readonly class NullEmailService implements EmailServiceInterface
{
    public function sendPasswordResetEmail(string $email, string $resetUrl): void
    {
    }

    public function sendVerificationEmail(string $email, User $user, EmailVerificationToken $token): void
    {
    }
}
