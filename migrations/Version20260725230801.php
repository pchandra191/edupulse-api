<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725230801 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add updated_at and deleted_at columns to role_permissions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role_permissions ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE role_permissions ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role_permissions DROP updated_at');
        $this->addSql('ALTER TABLE role_permissions DROP deleted_at');
    }
}
