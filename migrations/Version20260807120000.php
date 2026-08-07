<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create subjects and class_subjects tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE subjects (id UUID NOT NULL, institute_id UUID NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(30) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_subject_institute ON subjects (institute_id)');
        $this->addSql('CREATE INDEX idx_subject_status ON subjects (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_subject_institute_code ON subjects (institute_id, code)');
        $this->addSql('ALTER TABLE subjects ADD CONSTRAINT FK_subject_institute FOREIGN KEY (institute_id) REFERENCES institutes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE TABLE class_subjects (id UUID NOT NULL, class_id UUID NOT NULL, subject_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_class_subject ON class_subjects (class_id, subject_id)');
        $this->addSql('ALTER TABLE class_subjects ADD CONSTRAINT FK_class_subject_class FOREIGN KEY (class_id) REFERENCES classes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE class_subjects ADD CONSTRAINT FK_class_subject_subject FOREIGN KEY (subject_id) REFERENCES subjects (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE class_subjects');
        $this->addSql('DROP TABLE subjects');
    }
}
