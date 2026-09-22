<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260919210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent institute student registration links';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE institute_student_registration_links (
            id UUID NOT NULL,
            token VARCHAR(64) NOT NULL,
            is_active BOOLEAN NOT NULL,
            revoked_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            institute_id UUID NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_institute_student_registration_link_token ON institute_student_registration_links (token)');
        $this->addSql('CREATE INDEX idx_institute_student_registration_link_institute ON institute_student_registration_links (institute_id)');
        $this->addSql('CREATE INDEX idx_institute_student_registration_link_active ON institute_student_registration_links (is_active)');
        $this->addSql('ALTER TABLE institute_student_registration_links ADD CONSTRAINT FK_institute_student_registration_link_institute FOREIGN KEY (institute_id) REFERENCES institutes (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE institute_student_registration_links');
    }
}
