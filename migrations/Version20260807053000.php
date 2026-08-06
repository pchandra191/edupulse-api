<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807053000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create academic_years table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE academic_years (id UUID NOT NULL, institute_id UUID NOT NULL, name VARCHAR(50) NOT NULL, code VARCHAR(50) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, is_active BOOLEAN NOT NULL, status VARCHAR(20) NOT NULL, description TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX idx_academic_year_status ON academic_years (status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_academic_year_institute_code ON academic_years (institute_id, code)');
        $this->addSql('ALTER TABLE academic_years ADD CONSTRAINT FK_academic_year_institute FOREIGN KEY (institute_id) REFERENCES institutes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE academic_years');
    }
}
