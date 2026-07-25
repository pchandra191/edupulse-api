<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725230800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create roles, permissions, and role_permissions tables for role & permission management';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE roles (
            id UUID NOT NULL,
            code VARCHAR(50) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX idx_role_code ON roles (code)');
        $this->addSql('CREATE INDEX idx_role_status ON roles (status)');

        $this->addSql('CREATE TABLE permissions (
            id UUID NOT NULL,
            module VARCHAR(100) NOT NULL,
            group_name VARCHAR(100) NOT NULL,
            code VARCHAR(100) NOT NULL,
            name VARCHAR(100) NOT NULL,
            description TEXT DEFAULT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX idx_permission_code ON permissions (code)');
        $this->addSql('CREATE INDEX idx_permission_module ON permissions (module)');
        $this->addSql('CREATE INDEX idx_permission_group ON permissions (group_name)');

        $this->addSql('CREATE TABLE role_permissions (
            id UUID NOT NULL,
            role_id UUID NOT NULL,
            permission_id UUID NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX uniq_role_permission ON role_permissions (role_id, permission_id)');
        $this->addSql('CREATE INDEX idx_role_permission_role ON role_permissions (role_id)');
        $this->addSql('CREATE INDEX idx_role_permission_permission ON role_permissions (permission_id)');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT fk_role_permission_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE role_permissions ADD CONSTRAINT fk_role_permission_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE role_permissions DROP CONSTRAINT fk_role_permission_role');
        $this->addSql('ALTER TABLE role_permissions DROP CONSTRAINT fk_role_permission_permission');
        $this->addSql('DROP TABLE role_permissions');
        $this->addSql('DROP TABLE permissions');
        $this->addSql('DROP TABLE roles');
    }
}
