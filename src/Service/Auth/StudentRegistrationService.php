<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\DTO\Auth\StudentRegistrationRequestDTO;
use App\DTO\Auth\StudentRegistrationResponseDTO;
use App\Entity\EmailVerificationToken;
use App\Entity\Institute;
use App\Entity\InstituteStudentRegistrationLink;
use App\Entity\User;
use App\Enum\UserRole;
use App\Enum\UserStatus;
use App\Exception\AuthenticationException;
use App\Common\Exception\BusinessException;
use App\Repository\EmailVerificationTokenRepository;
use App\Repository\InstituteRepository;
use App\Repository\InstituteStudentRegistrationLinkRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Notification\EmailServiceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

readonly class StudentRegistrationService
{
    public function __construct(
        private InstituteRepository $instituteRepository,
        private InstituteStudentRegistrationLinkRepository $registrationLinkRepository,
        private UserRepository $userRepository,
        private EmailVerificationTokenRepository $emailVerificationTokenRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private AuthService $authService,
        private EmailServiceInterface $emailService,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private string $studentPortalUrl,
    ) {
    }

    public function register(StudentRegistrationRequestDTO $request, ?Institute $institute = null): StudentRegistrationResponseDTO
    {
        $email = mb_strtolower(trim($request->email));

        if ($this->userRepository->emailExists($email)) {
            throw new BusinessException('Email already exists.');
        }

        if ($institute !== null) {
            $this->requireActiveInstitute($institute);
        }

        $user = new User();
        $user->setFirstName($request->firstName);
        $user->setLastName($request->lastName);
        $user->setEmail($email);
        $user->setPhone($request->phone);
        $user->setRole(UserRole::Student);
        $user->setStatus(UserStatus::Active);
        $user->setInstitute($institute);
        $user->setPassword($this->passwordHasher->hashPassword($user, $request->password));

        try {
            $this->entityManager->beginTransaction();

            $this->entityManager->persist($user);
            $this->entityManager->flush();

            $selector = bin2hex(random_bytes(16));
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);

            $verificationToken = new EmailVerificationToken();
            $verificationToken->setEmail($user->getEmail());
            $verificationToken->setUser($user);
            $verificationToken->setSelector($selector);
            $verificationToken->setTokenHash($tokenHash);
            $verificationToken->setExpiresAt(new \DateTimeImmutable('+24 hours'));

            $this->entityManager->persist($verificationToken);
            $this->entityManager->flush();

            $verificationUrl = $this->urlGenerator->generate('auth_verify_email', [], UrlGeneratorInterface::ABSOLUTE_URL);
            $this->emailService->sendVerificationEmail($user->getEmail(), $user, $verificationToken);

            $this->entityManager->commit();

            $this->logger->info('Student registered successfully.', ['email' => $email]);
        } catch (UniqueConstraintViolationException $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw new BusinessException('Email already exists.', previous: $e);
        } catch (\Throwable $e) {
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            $this->logger->error('Failed to register student.', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return new StudentRegistrationResponseDTO(email: $email);
    }

    public function resolveInstituteById(?string $instituteId): ?Institute
    {
        if ($instituteId === null || trim($instituteId) === '') {
            return null;
        }

        try {
            $id = new Uuid($instituteId);
        } catch (\InvalidArgumentException $e) {
            throw new AuthenticationException('Selected institute not found.', previous: $e);
        }

        $institute = $this->instituteRepository->findById((string) $id);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Selected institute not found.');
        }

        $this->requireActiveInstitute($institute);

        return $institute;
    }

    public function resolveInstituteByToken(string $token): Institute
    {
        $token = trim($token);

        if ($token === '') {
            throw new AuthenticationException('Invalid registration link.');
        }

        $link = $this->registrationLinkRepository->findOneActiveByToken($token);

        if (!$link instanceof InstituteStudentRegistrationLink) {
            throw new AuthenticationException('Invalid or expired registration link.');
        }

        $instituteId = (string) $link->getInstitute()->getId();
        $institute = $this->instituteRepository->findById($instituteId);

        if (!$institute instanceof Institute) {
            throw new AuthenticationException('Institute not found.');
        }

        $this->requireActiveInstitute($institute);

        return $institute;
    }

    public function getInstituteInfo(string $token): InstituteRegistrationInfoResponseDTO
    {
        $institute = $this->resolveInstituteByToken($token);

        return new InstituteRegistrationInfoResponseDTO(
            id: (string) $institute->getId(),
            name: $institute->getName(),
            logo: $institute->getLogo(),
        );
    }

    public function getOrCreateRegistrationLink(Institute $institute): StudentRegistrationLinkResponseDTO
    {
        $this->requireActiveInstitute($institute);

        $link = $this->registrationLinkRepository->findOneActiveByInstitute($institute);

        if (!$link instanceof InstituteStudentRegistrationLink) {
            $link = new InstituteStudentRegistrationLink();
            $link->setToken($this->generateToken());
            $link->setInstitute($institute);
            $this->entityManager->persist($link);
            $this->entityManager->flush();
        }

        $registrationUrl = rtrim($this->studentPortalUrl, '/') . '/register/institute/' . $link->getToken();

        return new StudentRegistrationLinkResponseDTO(
            registrationUrl: $registrationUrl,
        );
    }

    public function resendVerification(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        $user = $this->userRepository->findByEmail($email);

        if (!$user instanceof User || $user->isVerified()) {
            return false;
        }

        $existingToken = $this->emailVerificationTokenRepository->findActiveByUser($user);
        if ($existingToken !== null) {
            $this->entityManager->remove($existingToken);
        }

        $selector = bin2hex(random_bytes(16));
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);

        $verificationToken = new EmailVerificationToken();
        $verificationToken->setEmail($user->getEmail());
        $verificationToken->setUser($user);
        $verificationToken->setSelector($selector);
        $verificationToken->setTokenHash($tokenHash);
        $verificationToken->setExpiresAt(new \DateTimeImmutable('+24 hours'));

        $this->entityManager->persist($verificationToken);
        $this->entityManager->flush();

        $this->emailService->sendVerificationEmail($user->getEmail(), $user, $verificationToken);

        $this->logger->info('Verification email resent.', ['email' => $email]);

        return true;
    }

    public function verifyEmail(string $selector): ?User
    {
        $token = $this->emailVerificationTokenRepository->findBySelector($selector);

        if (!$token instanceof EmailVerificationToken) {
            return null;
        }

        $now = new \DateTimeImmutable();

        if (!$token->isValid($now)) {
            return null;
        }

        $user = $token->getUser();

        if (!$user instanceof User) {
            return null;
        }

        $this->entityManager->beginTransaction();
        try {
            $user->verify();
            $token->markAsUsed();
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        return $user;
    }

    private function requireActiveInstitute(Institute $institute): void
    {
        if (!$institute->isActive()) {
            throw InstituteNotActiveException::create($institute->getStatus()->value);
        }
    }

    private function generateToken(): string
    {
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $token = bin2hex(random_bytes(32));

            if ($this->registrationLinkRepository->findOneBy(['token' => $token]) === null) {
                return $token;
            }
        }

        throw new \RuntimeException('Unable to generate a unique student registration token.');
    }
}
