<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create examinations and exam_subjects tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE examinations (id UUID NOT NULL, institute_id UUID NOT NULL, academic_year_id UUID NOT NULL, academic_class_id UUID NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(30) NOT NULL, description TEXT DEFAULT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_examination_institute ON examinations (institute_id)');
        $this->addSql('CREATE INDEX idx_examination_academic_year ON examinations (academic_year_id)');
        $this->addSql('CREATE INDEX idx_examination_academic_class ON examinations (academic_class_id)');
        $this->addSql('CREATE INDEX idx_examination_status ON examinations (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_examination_institute_code ON examinations (institute_id, code)');
        $this->addSql('ALTER TABLE examinations ADD CONSTRAINT FK_examination_institute FOREIGN KEY (institute_id) REFERENCES institutes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE examinations ADD CONSTRAINT FK_examination_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE examinations ADD CONSTRAINT FK_examination_academic_class FOREIGN KEY (academic_class_id) REFERENCES classes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE exam_subjects (id UUID NOT NULL, examination_id UUID NOT NULL, subject_id UUID NOT NULL, section_id UUID DEFAULT NULL, exam_date DATE DEFAULT NULL, start_time TIME DEFAULT NULL, end_time TIME DEFAULT NULL, duration INTEGER DEFAULT NULL, max_marks INTEGER DEFAULT NULL, passing_marks INTEGER DEFAULT NULL, status VARCHAR(20) NOT NULL, remarks TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_exam_subject_examination ON exam_subjects (examination_id)');
        $this->addSql('CREATE INDEX idx_exam_subject_subject ON exam_subjects (subject_id)');
        $this->addSql('CREATE INDEX idx_exam_subject_section ON exam_subjects (section_id)');
        $this->addSql('CREATE INDEX idx_exam_subject_status ON exam_subjects (status)');
        $this->addSql('ALTER TABLE exam_subjects ADD CONSTRAINT FK_exam_subject_examination FOREIGN KEY (examination_id) REFERENCES examinations (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE exam_subjects ADD CONSTRAINT FK_exam_subject_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE exam_subjects ADD CONSTRAINT FK_exam_subject_section FOREIGN KEY (section_id) REFERENCES sections (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE exam_subjects DROP CONSTRAINT FK_exam_subject_section');
        $this->addSql('ALTER TABLE exam_subjects DROP CONSTRAINT FK_exam_subject_subject');
        $this->addSql('ALTER TABLE exam_subjects DROP CONSTRAINT FK_exam_subject_examination');
        $this->addSql('DROP TABLE exam_subjects');

        $this->addSql('ALTER TABLE examinations DROP CONSTRAINT FK_examination_academic_class');
        $this->addSql('ALTER TABLE examinations DROP CONSTRAINT FK_examination_academic_year');
        $this->addSql('ALTER TABLE examinations DROP CONSTRAINT FK_examination_institute');
        $this->addSql('DROP TABLE examinations');
    }
}
