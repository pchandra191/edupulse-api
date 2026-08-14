<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260814240000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update student enrollment uniqueness constraint to include academic_class_id';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_enrollment_student_year');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_student_year_class ON student_enrollments (student_id, academic_year_id, academic_class_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_enrollment_student_year_class');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_student_year ON student_enrollments (student_id, academic_year_id)');
    }
}
