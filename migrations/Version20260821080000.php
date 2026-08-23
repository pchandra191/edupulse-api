<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260821080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_correct column to question_options to support MCQ correct-answer marking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question_options ADD is_correct BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE question_options DROP COLUMN is_correct');
    }
}
