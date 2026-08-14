<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260815003500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update admission number uniqueness to institute + academic year';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE student_enrollments ADD institute_id UUID DEFAULT NULL');
        $this->addSql('UPDATE student_enrollments se SET institute_id = c.institute_id FROM classes c WHERE se.academic_class_id = c.id');
        $this->addSql('ALTER TABLE student_enrollments ALTER COLUMN institute_id SET NOT NULL');
        $this->addSql('DROP INDEX uniq_enrollment_admission');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_admission ON student_enrollments (institute_id, academic_year_id, admission_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_enrollment_admission');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_admission ON student_enrollments (academic_class_id, admission_number)');
        $this->addSql('ALTER TABLE student_enrollments ALTER COLUMN institute_id DROP NOT NULL');
        $this->addSql('ALTER TABLE student_enrollments DROP institute_id');
    }
}
