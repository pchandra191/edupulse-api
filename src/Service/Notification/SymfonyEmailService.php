<?php
declare(strict_types=1);

namespace App\Service\Notification;

use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class SymfonyEmailService implements EmailServiceInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromEmail,
        private string $fromName,
    ) {
    }

    public function sendPasswordResetEmail(string $email, string $resetUrl): void
    {
        $auth/me (new Email())
            ->from("$this->fromName <$this->fromEmail>")
            ->to($email)
            ->subject('Reset your password')
            ->html("<p>Click <a href=\"$resetUrl\">here</a> to reset your password.</p>");

        $this->mailer->send($message);
    }
}
