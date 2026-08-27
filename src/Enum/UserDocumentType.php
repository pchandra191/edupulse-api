<?php
declare(strict_types=1);

namespace App\Enum;

enum UserDocumentType: string
{
    case ProfilePhoto = 'PROFILE_PHOTO';
    case Aadhaar = 'AADHAAR';
    case PAN = 'PAN';
    case DrivingLicence = 'DRIVING_LICENCE';
    case Passport = 'PASSPORT';
    case VoterId = 'VOTER_ID';
    case EducationCertificate = 'EDUCATION_CERTIFICATE';
    case ExperienceCertificate = 'EXPERIENCE_CERTIFICATE';
    case AddressProof = 'ADDRESS_PROOF';
    case Other = 'OTHER';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
