<?php

namespace App\Service\Notification;

use App\Entity\EmailVerificationToken;
use App\Entity\User;

interface EmailServiceInterface
{
    public function sendPasswordResetEmail(string $email, string $resetUrl): void;

    public function sendVerificationEmail(string $email, User $user, EmailVerificationToken $token): void;
}
