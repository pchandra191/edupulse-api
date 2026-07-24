<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725044058 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove ROLE_USER from user roles and normalize role storage';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE users SET roles = '[]' WHERE roles = '[\"ROLE_USER\"]'");
        $this->addSql("UPDATE users SET roles = '[\"ROLE_STUDENT\"]' WHERE roles = '[]'");
    }

    public function down(Schema $schema): void
    {
    }
}
