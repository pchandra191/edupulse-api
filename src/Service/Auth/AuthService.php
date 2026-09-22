<?php
declare(strict_types=1);

namespace App\Service\Auth;

use App\DTO\Auth\ChangePasswordRequestDTO;
use App\DTO\Auth\CurrentUserResponseDTO;
use App\DTO\Auth\EnrollmentResponseDTO;
use App\DTO\Auth\ForgotPasswordRequestDTO;
use App\DTO\Auth\InstituteResponseDTO;
use App\DTO\Auth\AcademicClassReferenceDTO;
use App\DTO\Auth\LoginRequest;
use App\DTO\Auth\LoginResponseDTO;
use App\DTO\Auth\LogoutRequestDTO;
use App\DTO\Auth\RefreshTokenRequestDTO;
use App\DTO\Auth\RefreshResponseDTO;
use App\DTO\Auth\ResetPasswordRequestDTO;
use App\Entity\Institute;
use App\Entity\PasswordResetToken;
use App\Entity\StudentEnrollment;
use App\Entity\User;
use App\Enum\InstituteStatus;
use App\Exception\AuthenticationException;
use App\Exception\ExpiredResetTokenException;
use App\Exception\InvalidCredentialsException;
use App\Exception\InvalidRefreshTokenException;
use App\Exception\InstituteNotActiveException;
use App\Exception\UserDisabledException;
use App\Exception\UserNotVerifiedException;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\StudentEnrollmentRepository;
use App\Repository\UserRepository;
use App\Service\Notification\EmailServiceInterface;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

readonly class AuthService
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private UserPasswordHasherInterface $passwordHasher,
        private UserRepository $userRepository,
        private RefreshTokenManagerInterface $refreshTokenManager,
        private EntityManagerInterface $entityManager,
        private PasswordResetTokenRepository $passwordResetTokenRepository,
        private StudentEnrollmentRepository $studentEnrollmentRepository,
        private EmailServiceInterface $emailService,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private int $refreshTokenTtl,
    ) {
    }

    public function login(LoginRequest $request): LoginResponseDTO
    {
        $this->logger->info('Login attempt', ['email' => $request->email]);

        $user = $this->userRepository->findActiveByIdentifier($request->email);

        if (!$user) {
            $this->logger->warning('Login failed: user not found', ['email' => $request->email]);
            throw InvalidCredentialsException::create();
        }

        $this->validateUser($user);

        if (!$this->passwordHasher->isPasswordValid($user, $request->password)) {
            $this->logger->warning('Login failed: invalid password', ['email' => $request->email]);
            throw InvalidCredentialsException::create();
        }

        $result = $this->authenticate($user);

        $this->logger->info('Login successful', ['email' => $request->email]);

        return $result;
    }

    public function authenticate(User $user): LoginResponseDTO
    {
        $token = $this->issueJwt($user);
        $refreshToken = $this->createRefreshToken($user);
        $userProfile = $this->getCurrentUserProfile($user);

        return new LoginResponseDTO(
            accessToken: $token,
            refreshToken: $refreshToken->getRefreshToken(),
            expiresAt: $this->getJwtExpiration($token),
            user: $userProfile,
        );
    }

    public function getCurrentUserProfile(UserInterface $user): CurrentUserResponseDTO
    {
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user is not an instance of App\Entity\User.');
        }

        $institute = null;

        if ($user->getInstitute() instanceof Institute) {
            $institute = new InstituteResponseDTO(
                id: (string) $user->getInstitute()->getId(),
                name: $user->getInstitute()->getName(),
                code: $user->getInstitute()->getCode(),
                logo: $user->getInstitute()->getLogo(),
                status: $user->getInstitute()->getStatus()->value,
            );
        }

        $enrollments = array_map(
            fn(StudentEnrollment $se) => new EnrollmentResponseDTO(
                id: (string) $se->getId(),
                status: $se->getStatus()->value,
                academicClass: $se->getAcademicClass() instanceof \App\Entity\AcademicClass
                    ? new AcademicClassReferenceDTO(
                        id: (string) $se->getAcademicClass()->getId(),
                        name: $se->getAcademicClass()->getName(),
                        startDate: $se->getAcademicClass()->getStartDate()?->format(DateTimeInterface::ATOM),
                        endDate: $se->getAcademicClass()->getEndDate()?->format(DateTimeInterface::ATOM),
                    )
                    : null,
            ),
            $this->studentEnrollmentRepository->findBy(['student' => $user], ['createdAt' => 'DESC'])
        );

        return new CurrentUserResponseDTO(
            id: (string) $user->getId(),
            email: $user->getEmail(),
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            roles: $user->getRoles(),
            institute: $institute,
            enrollments: $enrollments,
        );
    }

    public function refresh(RefreshTokenRequestDTO $request): RefreshResponseDTO
    {
        $this->logger->info('Refresh token attempt');

        $refreshToken = $this->refreshTokenManager->get($request->refreshToken);

        if (!$refreshToken || !$refreshToken->isValid()) {
            $this->logger->warning('Refresh token failed: invalid or expired');
            throw InvalidRefreshTokenException::create();
        }

        $user = $this->userRepository->findActiveByIdentifier($refreshToken->getUsername());

        if (!$user) {
            $this->logger->warning('Refresh token failed: user not found');
            throw InvalidRefreshTokenException::create();
        }

        $token = $this->issueJwt($user);
        $newRefreshToken = $this->rotateRefreshToken($refreshToken, $user);

        $this->logger->info('Refresh token successful');

        return new RefreshResponseDTO(
            accessToken: $token,
            refreshToken: $newRefreshToken->getRefreshToken(),
            expiresAt: $this->getJwtExpiration($token),
        );
    }

    public function logout(LogoutRequestDTO $request): void
    {
        $this->logger->info('Logout attempt');

        $refreshToken = $this->refreshTokenManager->get($request->refreshToken);

        if ($refreshToken && $refreshToken->isValid()) {
            $this->refreshTokenManager->delete($refreshToken);
        }

        $this->logger->info('Logout successful');
    }

    public function changePassword(UserInterface $user, ChangePasswordRequestDTO $request): void
    {
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user is not an instance of App\Entity\User.');
        }

        $this->logger->info('Password change attempt', ['email' => $user->getEmail()]);

        if (!$this->passwordHasher->isPasswordValid($user, $request->oldPassword)) {
            $this->logger->warning('Password change failed: invalid current password', ['email' => $user->getEmail()]);
            throw new AuthenticationException('Current password is incorrect.');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $request->newPassword);

        $user->setPassword($hashedPassword);

        $this->entityManager->beginTransaction();
        try {
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            throw $e;
        }

        $this->logger->info('Password changed successfully', ['email' => $user->getEmail()]);
    }

    public function forgotPassword(ForgotPasswordRequestDTO $request): void
    {
        $this->logger->info('Forgot password attempt', ['email' => $request->email]);

        $user = $this->userRepository->findActiveByIdentifier($request->email);

        if (!$user) {
            $this->logger->warning('Forgot password failed: user not found', ['email' => $request->email]);
            return;
        }

        $selector = bin2hex(random_bytes(16));
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);

        $resetToken = new PasswordResetToken();
        $resetToken->setEmail($user->getEmail());
        $resetToken->setSelector($selector);
        $resetToken->setTokenHash($tokenHash);
        $resetToken->setExpiresAt(new \DateTimeImmutable('+30 minutes'));

        $this->entityManager->persist($resetToken);
        $this->entityManager->flush();

        $resetUrl = $this->urlGenerator->generate('auth_reset_password', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $this->emailService->sendPasswordResetEmail($user->getEmail(), $resetUrl . '?token=' . $selector . ':' . $rawToken);

        $this->logger->info('Forgot password successful', ['email' => $request->email]);
    }

    public function resetPassword(ResetPasswordRequestDTO $request): void
    {
        $this->logger->info('Reset password attempt');

        $parts = explode(':', $request->token);

        if (count($parts) !== 2) {
            throw ExpiredResetTokenException::create();
        }

        [$selector, $rawToken] = $parts;

        $now = new \DateTimeImmutable();
        $resetToken = $this->passwordResetTokenRepository->findBySelector($selector);

        if (!$resetToken || !password_verify($rawToken, $resetToken->getTokenHash()) || !$resetToken->isValid($now)) {
            $this->logger->warning('Reset password failed: invalid or expired token');
            throw ExpiredResetTokenException::create();
        }

        $user = $this->userRepository->findActiveByIdentifier($resetToken->getEmail());

        if (!$user) {
            $this->logger->warning('Reset password failed: user not found');
            throw ExpiredResetTokenException::create();
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $request->newPassword);

        $user->setPassword($hashedPassword);
        $resetToken->markAsUsed();

        $this->entityManager->flush();

        $this->logger->info('Reset password successful', ['email' => $user->getEmail()]);
    }

    private function validateUser(User $user): void
    {
        if (!$user->isActive()) {
            throw UserDisabledException::create();
        }

        if (!$user->isVerified()) {
            throw UserNotVerifiedException::create();
        }

        if ($user->isDeleted()) {
            throw InvalidCredentialsException::create();
        }

        if ($user->getInstitute() instanceof Institute) {
            $instituteStatus = $user->getInstitute()->getStatus();

            if ($instituteStatus !== InstituteStatus::Active) {
                throw InstituteNotActiveException::create($instituteStatus->value);
            }
        }
    }

    private function issueJwt(User $user): string
    {
        return $this->jwtManager->create($user);
    }

    private function createRefreshToken(User $user): RefreshToken
    {
        $refreshToken = RefreshToken::createForUserWithTtl(
            bin2hex(random_bytes(64)),
            $user,
            $this->refreshTokenTtl
        );

        $this->refreshTokenManager->save($refreshToken);

        return $refreshToken;
    }

    private function rotateRefreshToken(RefreshToken $oldToken, User $user): RefreshToken
    {
        $newRefreshToken = RefreshToken::createForUserWithTtl(
            bin2hex(random_bytes(64)),
            $user,
            $this->refreshTokenTtl
        );

        $this->refreshTokenManager->delete($oldToken);
        $this->refreshTokenManager->save($newRefreshToken);

        return $newRefreshToken;
    }

    private function getJwtExpiration(string $token): ?\DateTimeImmutable
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return isset($payload['exp']) ? (new \DateTimeImmutable('@' . $payload['exp'])) : null;
    }
}
