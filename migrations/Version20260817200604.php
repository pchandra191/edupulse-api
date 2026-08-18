<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817200604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create question_papers, questions, question_options tables and pivot tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE question_papers (id UUID NOT NULL, institute_id UUID NOT NULL, academic_year_id UUID NOT NULL, subject_id UUID NOT NULL, examination_id UUID DEFAULT NULL, title VARCHAR(255) NOT NULL, date DATE NOT NULL, duration INTEGER NOT NULL, maximum_marks INTEGER NOT NULL, instructions TEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_by CHAR(36) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_question_paper_institute ON question_papers (institute_id)');
        $this->addSql('CREATE INDEX idx_question_paper_academic_year ON question_papers (academic_year_id)');
        $this->addSql('CREATE INDEX idx_question_paper_subject ON question_papers (subject_id)');
        $this->addSql('CREATE INDEX idx_question_paper_examination ON question_papers (examination_id)');
        $this->addSql('CREATE INDEX idx_question_paper_status ON question_papers (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_question_paper_institute_title ON question_papers (institute_id, title)');
        $this->addSql('ALTER TABLE question_papers ADD CONSTRAINT FK_question_paper_institute FOREIGN KEY (institute_id) REFERENCES institutes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE question_papers ADD CONSTRAINT FK_question_paper_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE question_papers ADD CONSTRAINT FK_question_paper_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE question_papers ADD CONSTRAINT FK_question_paper_examination FOREIGN KEY (examination_id) REFERENCES examinations (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE questions (id UUID NOT NULL, question_paper_id UUID NOT NULL, type VARCHAR(20) NOT NULL, question_text TEXT NOT NULL, marks INTEGER NOT NULL, display_order INTEGER NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_question_paper ON questions (question_paper_id)');
        $this->addSql('CREATE INDEX idx_question_type ON questions (type)');
        $this->addSql('CREATE INDEX idx_question_display_order ON questions (question_paper_id, display_order)');
        $this->addSql('ALTER TABLE questions ADD CONSTRAINT FK_question_question_paper FOREIGN KEY (question_paper_id) REFERENCES question_papers (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE question_options (id UUID NOT NULL, question_id UUID NOT NULL, option_text TEXT NOT NULL, display_order INTEGER NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_question_option_question ON question_options (question_id)');
        $this->addSql('CREATE INDEX idx_question_option_display_order ON question_options (question_id, display_order)');
        $this->addSql('ALTER TABLE question_options ADD CONSTRAINT FK_question_option_question FOREIGN KEY (question_id) REFERENCES questions (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE question_paper_classes (question_paper_id UUID NOT NULL, class_id UUID NOT NULL, PRIMARY KEY(question_paper_id, class_id))');
        $this->addSql('CREATE INDEX idx_question_paper_class_paper ON question_paper_classes (question_paper_id)');
        $this->addSql('CREATE INDEX idx_question_paper_class_class ON question_paper_classes (class_id)');
        $this->addSql('ALTER TABLE question_paper_classes ADD CONSTRAINT FK_question_paper_class_paper FOREIGN KEY (question_paper_id) REFERENCES question_papers (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE question_paper_classes ADD CONSTRAINT FK_question_paper_class_class FOREIGN KEY (class_id) REFERENCES classes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');

        $this->addSql('CREATE TABLE question_paper_sections (question_paper_id UUID NOT NULL, section_id UUID NOT NULL, PRIMARY KEY(question_paper_id, section_id))');
        $this->addSql('CREATE INDEX idx_question_paper_section_paper ON question_paper_sections (question_paper_id)');
        $this->addSql('CREATE INDEX idx_question_paper_section_section ON question_paper_sections (section_id)');
        $this->addSql('ALTER TABLE question_paper_sections ADD CONSTRAINT FK_question_paper_section_paper FOREIGN KEY (question_paper_id) REFERENCES question_papers (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE question_paper_sections ADD CONSTRAINT FK_question_paper_section_section FOREIGN KEY (section_id) REFERENCES sections (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question_paper_sections DROP CONSTRAINT FK_question_paper_section_section');
        $this->addSql('ALTER TABLE question_paper_sections DROP CONSTRAINT FK_question_paper_section_paper');
        $this->addSql('DROP TABLE question_paper_sections');

        $this->addSql('ALTER TABLE question_paper_classes DROP CONSTRAINT FK_question_paper_class_class');
        $this->addSql('ALTER TABLE question_paper_classes DROP CONSTRAINT FK_question_paper_class_paper');
        $this->addSql('DROP TABLE question_paper_classes');

        $this->addSql('ALTER TABLE question_options DROP CONSTRAINT FK_question_option_question');
        $this->addSql('DROP TABLE question_options');

        $this->addSql('ALTER TABLE questions DROP CONSTRAINT FK_question_question_paper');
        $this->addSql('DROP TABLE questions');

        $this->addSql('ALTER TABLE question_papers DROP CONSTRAINT FK_question_paper_examination');
        $this->addSql('ALTER TABLE question_papers DROP CONSTRAINT FK_question_paper_subject');
        $this->addSql('ALTER TABLE question_papers DROP CONSTRAINT FK_question_paper_academic_year');
        $this->addSql('ALTER TABLE question_papers DROP CONSTRAINT FK_question_paper_institute');
        $this->addSql('DROP TABLE question_papers');
    }
}
