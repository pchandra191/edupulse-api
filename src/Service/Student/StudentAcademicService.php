<?php
 declare(strict_types=1);

 namespace App\Service\Student;

 use App\DTO\Student\AcademicDetailsResponseDTO;
 use App\DTO\Student\UpdateAcademicDetailsRequestDTO;
 use App\Entity\User;
 use App\Entity\UserProfile;
 use App\Exception\AuthenticationException;
 use App\Repository\UserProfileRepository;
 use Doctrine\ORM\EntityManagerInterface;
 use Psr\Log\LoggerInterface;

 readonly class StudentAcademicService
 {
     public function __construct(
         private EntityManagerInterface $entityManager,
         private UserProfileRepository $userProfileRepository,
         private LoggerInterface $logger,
     ) {
     }

     public function getAcademicDetails(User $user): AcademicDetailsResponseDTO
     {
         if ($user->getInstitute() !== null) {
             throw new \InvalidArgumentException('Academic details for institute students are managed through enrollment.');
         }

         $profile = $this->userProfileRepository->findByUserId($user->getId());

         return $this->mapToResponse($profile);
     }

     public function updateAcademicDetails(User $user, UpdateAcademicDetailsRequestDTO $request): AcademicDetailsResponseDTO
     {
         if ($user->getInstitute() !== null) {
             throw new \InvalidArgumentException('Academic details for institute students are managed through enrollment.');
         }

         $profile = $this->userProfileRepository->findByUserId($user->getId());

         if (!$profile instanceof UserProfile) {
             $profile = new UserProfile();
             $profile->setUserId($user->getId());
             $this->entityManager->persist($profile);
         }

         $this->updateFields($profile, $request);
         $this->entityManager->flush();

         return $this->mapToResponse($profile);
     }

     private function updateFields(UserProfile $profile, UpdateAcademicDetailsRequestDTO $request): void
     {
         $profile->setAcademicYear($request->academicYear);
         $profile->setAcademicClass($request->academicClass);
         $profile->setSection($request->section);
     }

     private function mapToResponse(?UserProfile $profile): AcademicDetailsResponseDTO
     {
         return new AcademicDetailsResponseDTO(
             academicYear: $profile?->getAcademicYear(),
             academicClass: $profile?->getAcademicClass(),
             section: $profile?->getSection(),
         );
     }
 }