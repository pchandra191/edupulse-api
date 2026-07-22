<?php

namespace App\Service\Notification;

readonly class NullEmailService implements EmailServiceInterface
{
    public function sendPasswordResetEmail(string $email, string $resetUrl): void
    {
    }
}
