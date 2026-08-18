<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817203500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add image column to questions table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE questions ADD image TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE questions DROP COLUMN image');
    }
}
