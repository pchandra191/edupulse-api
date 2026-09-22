<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\EmailVerificationToken;
use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class SymfonyEmailService implements EmailServiceInterface
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromEmail,
        private string $fromName,
        private Environment $twig,
        private string $studentPortalUrl,
    ) {
    }

    public function sendPasswordResetEmail(string $email, string $resetUrl): void
    {
        $message = (new Email())
            ->from("$this->fromName <$this->fromEmail>")
            ->to($email)
            ->subject('Reset your password')
            ->html("<p>Click <a href=\"$resetUrl\">here</a> to reset your password.</p>");

        $this->mailer->send($message);
    }

    public function sendVerificationEmail(string $email, User $user, EmailVerificationToken $token): void
    {
        $verificationUrl = rtrim($this->studentPortalUrl, '/') . '/verify-email?token=' . $token->getSelector();

        $firstName = $user->getFirstName() ?? '';

        $html = $this->twig->render('email/verify-email.html.twig', [
            'firstName' => $firstName,
            'verificationUrl' => $verificationUrl,
            'expiresAt' => $token->getExpiresAt()->format('M d, Y \a\t h:i A T'),
        ]);

        $message = (new Email())
            ->from("$this->fromName <$this->fromEmail>")
            ->to($email)
            ->subject('Verify your EduPulse email address')
            ->html($html);

        $this->mailer->send($message);
    }
}
