<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\DTO\Auth\ChangePasswordRequestDTO;
use App\DTO\Auth\CurrentUserResponseDTO;
use App\DTO\Auth\ForgotPasswordRequestDTO;
use App\DTO\Auth\LoginRequest;
use App\DTO\Auth\LoginResponseDTO;
use App\DTO\Auth\LogoutRequestDTO;
use App\DTO\Auth\RefreshTokenRequestDTO;
use App\DTO\Auth\RefreshResponseDTO;
use App\DTO\Auth\ResetPasswordRequestDTO;
use App\Service\Auth\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AuthController
{
    public function __construct(
        private AuthService $authService,
        private ValidatorInterface $validator,
        private Security $security,
    ) {
    }

    #[Route('/api/v1/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON payload.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $loginRequest = new LoginRequest(
            $data['email'] ?? '',
            $data['password'] ?? ''
        );

        $errors = $this->validator->validate($loginRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $formattedErrors,
            ], Response::HTTP_BAD_REQUEST);
        }

        /** @var LoginResponseDTO $result */
        $result = $this->authService->login($loginRequest);

        return new JsonResponse([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'token' => $result->token,
                'refresh_token' => $result->refreshToken,
                'expires_at' => $result->expiresAt?->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON payload.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $refreshRequest = new RefreshTokenRequestDTO(
            $data['refreshToken'] ?? ''
        );

        $errors = $this->validator->validate($refreshRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $formattedErrors,
            ], Response::HTTP_BAD_REQUEST);
        }

        /** @var RefreshResponseDTO $result */
        $result = $this->authService->refresh($refreshRequest);

        return new JsonResponse([
            'success' => true,
            'message' => 'Token refreshed successfully.',
            'data' => [
                'token' => $result->token,
                'refresh_token' => $result->refreshToken,
                'expires_at' => $result->expiresAt?->format(\DateTimeInterface::ATOM),
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON payload.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $logoutRequest = new LogoutRequestDTO(
            $data['refreshToken'] ?? ''
        );

        $errors = $this->validator->validate($logoutRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $formattedErrors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->authService->logout($logoutRequest);

        return new JsonResponse([
            'success' => true,
            'message' => 'Logged out successfully.',
            'data' => new \stdClass(),
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/me', name: 'auth_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->security->getUser();

        if (!$user) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Unauthenticated.',
                'errors' => [],
            ], Response::HTTP_UNAUTHORIZED);
        }

        /** @var CurrentUserResponseDTO $profile */
        $profile = $this->authService->getCurrentUserProfile($user);

        return new JsonResponse([
            'success' => true,
            'message' => 'User profile retrieved successfully.',
            'data' => [
                'id' => $profile->id,
                'email' => $profile->email,
                'firstName' => $profile->firstName,
                'lastName' => $profile->lastName,
                'roles' => $profile->roles,
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/forgot-password', name: 'auth_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON payload.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $forgotPasswordRequest = new ForgotPasswordRequestDTO(
            $data['email'] ?? ''
        );

        $errors = $this->validator->validate($forgotPasswordRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $formattedErrors,
            ], Response::HTTP_BAD_REQUEST);
        }

        $this->authService->forgotPassword($forgotPasswordRequest);

        return new JsonResponse([
            'success' => true,
            'message' => 'If an account with that email exists, a password reset link will be sent.',
            'data' => new \stdClass(),
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/reset-password', name: 'auth_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON payload.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $resetPasswordRequest = new ResetPasswordRequestDTO(
            $data['token'] ?? '',
            $data['newPassword'] ?? '',
            $data['confirmPassword'] ?? ''
        );

        $errors = $this->validator->validate($resetPasswordRequest);

        if (count($errors) > 0) {
            $formattedErrors = [];

            foreach ($errors as $error) {
                $formattedErrors[$error->getPropertyPath()][] = $error->getMessage();
            }

            return new JsonResponse([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $formattedErrors,
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->authService->resetPassword($resetPasswordRequest);
        } catch (\App\Exception\AuthenticationException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Password has been reset successfully.',
            'data' => new \stdClass(),
        ], Response::HTTP_OK);
    }
}
