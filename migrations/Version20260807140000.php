<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create teacher_assignments table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE teacher_assignments (id UUID NOT NULL, teacher_id UUID NOT NULL, academic_year_id UUID NOT NULL, academic_class_id UUID NOT NULL, section_id UUID DEFAULT NULL, subject_id UUID DEFAULT NULL, assignment_type VARCHAR(30) NOT NULL, remarks TEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_assignment_teacher ON teacher_assignments (teacher_id)');
        $this->addSql('CREATE INDEX idx_assignment_class ON teacher_assignments (academic_class_id)');
        $this->addSql('CREATE INDEX idx_assignment_status ON teacher_assignments (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_teacher_assignment ON teacher_assignments (teacher_id, academic_year_id, academic_class_id, section_id, subject_id)');
        $this->addSql('ALTER TABLE teacher_assignments ADD CONSTRAINT FK_teacher_assignment_teacher FOREIGN KEY (teacher_id) REFERENCES users (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE teacher_assignments ADD CONSTRAINT FK_teacher_assignment_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE teacher_assignments ADD CONSTRAINT FK_teacher_assignment_class FOREIGN KEY (academic_class_id) REFERENCES classes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE teacher_assignments ADD CONSTRAINT FK_teacher_assignment_section FOREIGN KEY (section_id) REFERENCES sections (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE teacher_assignments ADD CONSTRAINT FK_teacher_assignment_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE teacher_assignments');
    }
}
