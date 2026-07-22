<?php

namespace App\Service\Auth;

use App\DTO\Auth\CurrentUserResponseDTO;
use App\DTO\Auth\ChangePasswordRequestDTO;
use App\DTO\Auth\ForgotPasswordRequestDTO;
use App\DTO\Auth\LoginRequest;
use App\DTO\Auth\LogoutRequestDTO;
use App\DTO\Auth\RefreshTokenRequestDTO;
use App\DTO\Auth\ResetPasswordRequestDTO;
use App\Entity\PasswordResetToken;
use App\Entity\User;
use App\Exception\AuthenticationException;
use App\Repository\PasswordResetTokenRepository;
use App\Repository\UserRepository;
use App\Service\Notification\EmailServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Entity\RefreshToken;
use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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
        private EmailServiceInterface $emailService,
    ) {
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    public function login(LoginRequest $request): array
    {
        $user = $this->userRepository->findOneByEmail($request->email);

        if (!$user || !$this->passwordHasher->isPasswordValid($user, $request->password)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        $token = $this->jwtManager->create($user);

        $refreshToken = RefreshToken::createForUserWithTtl(
            bin2hex(random_bytes(64)),
            $user,
            30 * 24 * 3600
        );

        $this->refreshTokenManager->save($refreshToken);

        return [
            'token' => $token,
            'refresh_token' => $refreshToken->getRefreshToken(),
        ];
    }

    public function getCurrentUserProfile(UserInterface $user): CurrentUserResponseDTO
    {
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user is not an instance of App\Entity\User.');
        }

        return new CurrentUserResponseDTO(
            id: $user->getId() ?? '',
            email: $user->getEmail() ?? '',
            firstName: $user->getFirstName(),
            lastName: $user->getLastName(),
            roles: $user->getRoles(),
        );
    }

    /**
     * @return array{token: string, refresh_token: string, expires_at: string|null}
     */
    public function refresh(RefreshTokenRequestDTO $request): array
    {
        $refreshToken = $this->refreshTokenManager->get($request->refreshToken);

        if (!$refreshToken || !$refreshToken->isValid()) {
            throw new AuthenticationException('Invalid refresh token.');
        }

        $user = $this->userRepository->findOneByEmail($refreshToken->getUsername());

        if (!$user) {
            throw new AuthenticationException('User not found.');
        }

        $token = $this->jwtManager->create($user);

        $newRefreshToken = RefreshToken::createForUserWithTtl(
            bin2hex(random_bytes(64)),
            $user,
            30 * 24 * 3600
        );

        $this->refreshTokenManager->delete($refreshToken);
        $this->refreshTokenManager->save($newRefreshToken);

        $parts = explode('.', $token);
        $expiresAt = null;

        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            $expiresAt = isset($payload['exp']) ? (new \DateTimeImmutable('@' . $payload['exp']))->format(\DateTimeInterface::ATOM) : null;
        }

        return [
            'token' => $token,
            'refresh_token' => $newRefreshToken->getRefreshToken(),
            'expires_at' => $expiresAt,
        ];
    }

    public function logout(LogoutRequestDTO $request): void
    {
        $refreshToken = $this->refreshTokenManager->get($request->refreshToken);

        if ($refreshToken && $refreshToken->isValid()) {
            $this->refreshTokenManager->delete($refreshToken);
        }
    }

    public function changePassword(UserInterface $user, ChangePasswordRequestDTO $request): void
    {
        if (!$user instanceof User) {
            throw new \LogicException('Authenticated user is not an instance of App\Entity\User.');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $request->oldPassword)) {
            throw new AuthenticationException('Current password is incorrect.');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $request->newPassword);

        $user->setPassword($hashedPassword);

        $this->entityManager->flush();
    }

    public function forgotPassword(ForgotPasswordRequestDTO $request): void
    {
        $user = $this->userRepository->findOneByEmail($request->email);

        if (!$user) {
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = password_hash($rawToken, PASSWORD_DEFAULT);

        $resetToken = new PasswordResetToken();
        $resetToken->setEmail($user->getEmail());
        $resetToken->setToken($rawToken);
        $resetToken->setTokenHash($tokenHash);
        $resetToken->setExpiresAt(new \DateTimeImmutable('+30 minutes'));

        $this->entityManager->persist($resetToken);
        $this->entityManager->flush();
    }

    public function resetPassword(ResetPasswordRequestDTO $request): void
    {
        $now = new \DateTimeImmutable();

        $resetToken = $this->passwordResetTokenRepository->findByToken($request->token);

        if (
            !$resetToken
            || null === $resetToken->getTokenHash()
            || !password_verify($request->token, $resetToken->getTokenHash())
            || !$resetToken->isValid($now)
        ) {
            throw new AuthenticationException('Invalid or expired reset token.');
        }

        $user = $this->userRepository->findOneByEmail($resetToken->getEmail());

        if (!$user) {
            throw new AuthenticationException('User not found.');
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $request->newPassword);

        $user->setPassword($hashedPassword);

        $resetToken->setUsedAt($now);

        $this->entityManager->flush();
    }
}
