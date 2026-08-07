<?php
declare(strict_types=1);

namespace App\Enum;

enum AssignmentType: string
{
    case SubjectTeacher = 'SUBJECT_TEACHER';
    case ClassTeacher = 'CLASS_TEACHER';
    case BatchMentor = 'BATCH_MENTOR';
    case Coordinator = 'COORDINATOR';
}
