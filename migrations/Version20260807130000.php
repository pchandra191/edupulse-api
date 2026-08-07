<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create sections table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sections (id UUID NOT NULL, academic_class_id UUID NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(20) NOT NULL, capacity INT DEFAULT NULL, description TEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_section_status ON sections (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_section_class_code ON sections (academic_class_id, code)');
        $this->addSql('ALTER TABLE sections ADD CONSTRAINT FK_section_class FOREIGN KEY (academic_class_id) REFERENCES classes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sections');
    }
}
