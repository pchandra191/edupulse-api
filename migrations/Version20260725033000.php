<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725033000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing columns to users table: phone, department, status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD phone VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD department VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD status VARCHAR(20) NOT NULL DEFAULT \'ACTIVE\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP status');
        $this->addSql('ALTER TABLE users DROP department');
        $this->addSql('ALTER TABLE users DROP phone');
    }
}
