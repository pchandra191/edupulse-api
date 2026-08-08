<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create student_enrollments and enrollment_sections tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE student_enrollments (id UUID NOT NULL, student_id UUID NOT NULL, academic_year_id UUID NOT NULL, academic_class_id UUID NOT NULL, roll_number VARCHAR(20) DEFAULT NULL, admission_number VARCHAR(50) NOT NULL, admission_date DATE DEFAULT NULL, remarks TEXT DEFAULT NULL, is_current BOOLEAN NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_enrollment_student ON student_enrollments (student_id)');
        $this->addSql('CREATE INDEX idx_enrollment_class ON student_enrollments (academic_class_id)');
        $this->addSql('CREATE INDEX idx_enrollment_year ON student_enrollments (academic_year_id)');
        $this->addSql('CREATE INDEX idx_enrollment_status ON student_enrollments (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_student_year ON student_enrollments (student_id, academic_year_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_roll ON student_enrollments (academic_year_id, academic_class_id, roll_number)');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_admission ON student_enrollments (academic_class_id, admission_number)');
        $this->addSql('ALTER TABLE student_enrollments ADD CONSTRAINT FK_enrollment_student FOREIGN KEY (student_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_enrollments ADD CONSTRAINT FK_enrollment_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE student_enrollments ADD CONSTRAINT FK_enrollment_academic_class FOREIGN KEY (academic_class_id) REFERENCES classes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE enrollment_sections (id UUID NOT NULL, student_enrollment_id UUID NOT NULL, section_id UUID NOT NULL, status VARCHAR(20) NOT NULL, joined_at DATE DEFAULT NULL, left_at DATE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_enrollment_section_enrollment ON enrollment_sections (student_enrollment_id)');
        $this->addSql('CREATE INDEX idx_enrollment_section_section ON enrollment_sections (section_id)');
        $this->addSql('CREATE INDEX idx_enrollment_section_status ON enrollment_sections (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_enrollment_section ON enrollment_sections (student_enrollment_id, section_id)');
        $this->addSql('ALTER TABLE enrollment_sections ADD CONSTRAINT FK_enrollment_section_enrollment FOREIGN KEY (student_enrollment_id) REFERENCES student_enrollments (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE enrollment_sections ADD CONSTRAINT FK_enrollment_section_section FOREIGN KEY (section_id) REFERENCES sections (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE enrollment_sections');
        $this->addSql('DROP TABLE student_enrollments');
    }
}
