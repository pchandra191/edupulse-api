<?php
declare(strict_types=1);

namespace App\Enum;

enum TeacherDepartment: string
{
    case SCIENCE = 'SCIENCE';
    case MATHEMATICS = 'MATHEMATICS';
    case COMMERCE = 'COMMERCE';
    case HUMANITIES = 'HUMANITIES';
    case SOCIAL_SCIENCE = 'SOCIAL_SCIENCE';
    case ENGLISH = 'ENGLISH';
    case LANGUAGES = 'LANGUAGES';
    case COMPUTER_SCIENCE = 'COMPUTER_SCIENCE';
    case INFORMATION_TECHNOLOGY = 'INFORMATION_TECHNOLOGY';
    case PHYSICAL_EDUCATION = 'PHYSICAL_EDUCATION';
    case ADMINISTRATION = 'ADMINISTRATION';
    case OTHER = 'OTHER';

    public function getLabel(): string
    {
        return match ($this) {
            self::SCIENCE => 'Science',
            self::MATHEMATICS => 'Mathematics',
            self::COMMERCE => 'Commerce',
            self::HUMANITIES => 'Humanities',
            self::SOCIAL_SCIENCE => 'Social Science',
            self::ENGLISH => 'English',
            self::LANGUAGES => 'Languages',
            self::COMPUTER_SCIENCE => 'Computer Science',
            self::INFORMATION_TECHNOLOGY => 'Information Technology',
            self::PHYSICAL_EDUCATION => 'Physical Education',
            self::ADMINISTRATION => 'Administration',
            self::OTHER => 'Other',
        };
    }

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
