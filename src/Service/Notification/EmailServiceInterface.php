<?php

namespace App\Service\Notification;

interface EmailServiceInterface
{
    public function sendPasswordResetEmail(string $email, string $resetUrl): void;
}
