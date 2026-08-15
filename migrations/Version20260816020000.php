<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260816020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migrate existing teacher department values to enum format';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE users SET department = 'ADMINISTRATION' WHERE department = 'Administration'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE users SET department = 'Administration' WHERE department = 'ADMINISTRATION'");
    }
}
