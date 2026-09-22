<?php

declare(strict_types=1);

namespace App\Controller\Auth;

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
use App\DTO\Auth\StudentRegistrationRequestDTO;
use App\DTO\Auth\StudentRegistrationResponseDTO;
use App\DTO\Auth\VerifyEmailRequestDTO;
use App\DTO\Auth\ResendVerificationRequestDTO;
use App\Entity\EmailVerificationToken;
use App\Service\Auth\AuthService;
use App\Service\Auth\StudentRegistrationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use App\Service\Role\RolePermissionService;
use Symfony\Component\Uid\Uuid;


class AuthController
{
    public function __construct(
        private AuthService $authService,
        private StudentRegistrationService $studentRegistrationService,
        private ValidatorInterface $validator,
        private Security $security,
        private RolePermissionService $rolePermissionService,
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
                'accessToken' => $result->accessToken,
                'refresh_token' => $result->refreshToken,
                'expires_at' => $result->expiresAt?->format(\DateTimeInterface::ATOM),
                'user' => [
                    'id' => $result->user->id,
                    'email' => $result->user->email,
                    'first_name' => $result->user->firstName,
                    'last_name' => $result->user->lastName,
                    'roles' => $result->user->roles,
                ],
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/student/register', name: 'student_register', methods: ['POST'])]
    public function registerStudent(Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);

        if ($data === null) {
            return $this->invalidJsonResponse();
        }

        $registrationRequest = new StudentRegistrationRequestDTO(
            firstName: $data['firstName'] ?? '',
            lastName: $data['lastName'] ?? null,
            email: $data['email'] ?? '',
            phone: $data['phone'] ?? null,
            password: $data['password'] ?? '',
            confirmPassword: $data['confirmPassword'] ?? null,
            instituteId: $data['instituteId'] ?? null,
        );

        $errors = $this->validator->validate($registrationRequest);

        if (count($errors) > 0) {
            return $this->validationResponse($errors);
        }

        try {
            $institute = $this->studentRegistrationService->resolveInstituteById($registrationRequest->instituteId);
            $result = $this->studentRegistrationService->register($registrationRequest, $institute);
        } catch (\App\Exception\InstituteNotActiveException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_FORBIDDEN);
        } catch (\App\Exception\AuthenticationException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_NOT_FOUND);
        } catch (\App\Common\Exception\BusinessException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Registration successful. Please verify your email address.',
            'data' => [
                'email' => $result->email,
                'emailVerificationRequired' => true,
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/student-registration/institute/{token}', name: 'student_registration_institute_info', methods: ['GET'])]
    public function instituteRegistrationInfo(string $token): JsonResponse
    {
        try {
            $institute = $this->studentRegistrationService->getInstituteInfo($token);
        } catch (\App\Exception\InstituteNotActiveException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_FORBIDDEN);
        } catch (\App\Exception\AuthenticationException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Institute registration details retrieved successfully.',
            'data' => [
                'institute' => [
                    'id' => $institute->id,
                    'name' => $institute->name,
                    'logo' => $institute->logo,
                ],
            ],
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/student/register/institute/{token}', name: 'student_registration_institute', methods: ['POST'])]
    public function registerStudentWithInstitute(string $token, Request $request): JsonResponse
    {
        $data = $this->decodeJson($request);

        if ($data === null) {
            return $this->invalidJsonResponse();
        }

        if (array_key_exists('instituteId', $data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Institute must be selected through the registration link.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $registrationRequest = new StudentRegistrationRequestDTO(
            firstName: $data['firstName'] ?? '',
            lastName: $data['lastName'] ?? null,
            email: $data['email'] ?? '',
            phone: $data['phone'] ?? null,
            password: $data['password'] ?? '',
            confirmPassword: $data['confirmPassword'] ?? null,
        );

        $errors = $this->validator->validate($registrationRequest);

        if (count($errors) > 0) {
            return $this->validationResponse($errors);
        }

        try {
            $institute = $this->studentRegistrationService->resolveInstituteByToken($token);
            $result = $this->studentRegistrationService->register($registrationRequest, $institute);
        } catch (\App\Exception\InstituteNotActiveException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_FORBIDDEN);
        } catch (\App\Exception\AuthenticationException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_NOT_FOUND);
        } catch (\App\Common\Exception\BusinessException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Registration successful. Please verify your email address.',
            'data' => [
                'email' => $result->email,
                'emailVerificationRequired' => true,
            ],
        ], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/auth/verify-email', name: 'auth_verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON payload.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $verifyRequest = new VerifyEmailRequestDTO($data['token'] ?? '');

        $errors = $this->validator->validate($verifyRequest);

        if (count($errors) > 0) {
            return $this->validationResponse($errors);
        }

        try {
            $user = $this->studentRegistrationService->verifyEmail($verifyRequest->token);
        } catch (\App\Exception\AuthenticationException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        if ($user === null) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid or expired verification token.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'Email verified successfully. You can now sign in.',
            'data' => new \stdClass(),
        ], Response::HTTP_OK);
    }

    #[Route('/api/v1/auth/resend-verification', name: 'auth_resend_verification', methods: ['POST'])]
    public function resendVerification(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data)) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid JSON payload.',
                'errors' => [],
            ], Response::HTTP_BAD_REQUEST);
        }

        $resendRequest = new ResendVerificationRequestDTO($data['email'] ?? '');

        $errors = $this->validator->validate($resendRequest);

        if (count($errors) > 0) {
            return $this->validationResponse($errors);
        }

        try {
            $this->studentRegistrationService->resendVerification($resendRequest->email);
        } catch (\App\Exception\AuthenticationException $e) {
            return new JsonResponse([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => [],
            ], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'success' => true,
            'message' => 'If an unverified account exists with that email, a verification email has been sent.',
            'data' => new \stdClass(),
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
                'accessToken' => $result->accessToken,
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
                'institute' => $profile->institute !== null ? [
                    'id' => $profile->institute->id,
                    'name' => $profile->institute->name,
                    'code' => $profile->institute->code,
                    'logo' => $profile->institute->logo,
                    'status' => $profile->institute->status,
                ] : null,
                'permissions' => $this->rolePermissionService->getUserEffectivePermissions(new Uuid($profile->id)),
                'enrollments' => array_map(
                    fn(EnrollmentResponseDTO $e) => [
                        'id' => $e->id,
                        'status' => $e->status,
                        'academicClass' => $e->academicClass !== null ? [
                            'id' => $e->academicClass->id,
                            'name' => $e->academicClass->name,
                        ] : null,
                    ],
                    $profile->enrollments,
                ),
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

    private function decodeJson(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);

        return is_array($data) ? $data : null;
    }

    private function invalidJsonResponse(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'message' => 'Invalid JSON payload.',
            'errors' => [],
        ], Response::HTTP_BAD_REQUEST);
    }

    private function validationResponse(ConstraintViolationListInterface $errors): JsonResponse
    {
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

    private function registrationResponse(\App\DTO\Auth\LoginResponseDTO $result): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'message' => 'Student registration successful.',
            'data' => [
                'accessToken' => $result->accessToken,
                'refresh_token' => $result->refreshToken,
                'expires_at' => $result->expiresAt?->format(\DateTimeInterface::ATOM),
                'user' => [
                    'id' => $result->user->id,
                    'email' => $result->user->email,
                    'first_name' => $result->user->firstName,
                    'last_name' => $result->user->lastName,
                    'roles' => $result->user->roles,
                ],
            ],
        ], Response::HTTP_OK);
    }
}
