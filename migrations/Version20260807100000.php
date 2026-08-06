<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260807100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create classes table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE classes (id UUID NOT NULL, institute_id UUID NOT NULL, academic_year_id UUID NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(30) NOT NULL, description TEXT DEFAULT NULL, status VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_class_institute_code ON classes (institute_id, code)');
        $this->addSql('ALTER TABLE classes ADD CONSTRAINT FK_class_institute FOREIGN KEY (institute_id) REFERENCES institutes (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE classes ADD CONSTRAINT FK_class_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE classes');
    }
}
