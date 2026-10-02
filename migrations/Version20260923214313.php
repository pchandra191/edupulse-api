<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add ExamSet entity and extend Analysis with exam_set_id, subject metadata fields
 */
final class Version20260923214313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ExamSet entity and extend Analysis with exam_set_id, subject metadata fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE exam_sets (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, academic_year_id VARCHAR(36) DEFAULT NULL, academic_year VARCHAR(50) DEFAULT NULL, class_id VARCHAR(36) DEFAULT NULL, academic_class VARCHAR(50) DEFAULT NULL, section_id VARCHAR(36) DEFAULT NULL, section VARCHAR(50) DEFAULT NULL, exam_id VARCHAR(36) DEFAULT NULL, exam_name VARCHAR(100) DEFAULT NULL, exam_sub_type_id VARCHAR(36) DEFAULT NULL, exam_sub_type VARCHAR(100) DEFAULT NULL, student_id UUID NOT NULL, institute_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_exam_set_student ON exam_sets (student_id)');
        $this->addSql('CREATE INDEX idx_exam_set_institute ON exam_sets (institute_id)');
        $this->addSql('CREATE INDEX idx_exam_set_academic_year ON exam_sets (academic_year_id)');
        $this->addSql('CREATE INDEX idx_exam_set_class ON exam_sets (class_id)');
        $this->addSql('CREATE INDEX idx_exam_set_exam ON exam_sets (exam_id)');
        $this->addSql('ALTER TABLE exam_sets ADD CONSTRAINT FK_1969C362CB944F1A FOREIGN KEY (student_id) REFERENCES users (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE exam_sets ADD CONSTRAINT FK_1969C362697B0F4C FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE analyses ADD subject_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD total_marks INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD scored_marks INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD student_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD roll_number VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD exam_set_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD subject_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_AC86883CDBC85B92 FOREIGN KEY (exam_set_id) REFERENCES exam_sets (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE analyses ADD CONSTRAINT FK_AC86883C23EDC87 FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_analysis_exam_set ON analyses (exam_set_id)');
        $this->addSql('CREATE INDEX idx_analysis_subject ON analyses (subject_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_analysis_exam_set_subject ON analyses (exam_set_id, subject_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_analysis_exam_set_subject ON analyses');
        $this->addSql('DROP INDEX idx_analysis_subject ON analyses');
        $this->addSql('DROP INDEX idx_analysis_exam_set ON analyses');
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT FK_AC86883C23EDC87');
        $this->addSql('ALTER TABLE analyses DROP CONSTRAINT FK_AC86883CDBC85B92');
        $this->addSql('ALTER TABLE analyses DROP subject_id');
        $this->addSql('ALTER TABLE analyses DROP exam_set_id');
        $this->addSql('ALTER TABLE analyses DROP roll_number');
        $this->addSql('ALTER TABLE analyses DROP student_name');
        $this->addSql('ALTER TABLE analyses DROP scored_marks');
        $this->addSql('ALTER TABLE analyses DROP total_marks');
        $this->addSql('ALTER TABLE analyses DROP subject_name');
        $this->addSql('DROP INDEX idx_exam_set_exam ON exam_sets');
        $this->addSql('DROP INDEX idx_exam_set_class ON exam_sets');
        $this->addSql('DROP INDEX idx_exam_set_academic_year ON exam_sets');
        $this->addSql('DROP INDEX idx_exam_set_institute ON exam_sets');
        $this->addSql('DROP INDEX idx_exam_set_student ON exam_sets');
        $this->addSql('ALTER TABLE exam_sets DROP CONSTRAINT FK_1969C362697B0F4C');
        $this->addSql('ALTER TABLE exam_sets DROP CONSTRAINT FK_1969C362CB944F1A');
        $this->addSql('DROP TABLE exam_sets');
    }
}
