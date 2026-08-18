<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260817203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at column to questions and question_options tables';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE questions ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE question_options ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question_options DROP COLUMN deleted_at');
        $this->addSql('ALTER TABLE questions DROP COLUMN deleted_at');
    }
}
