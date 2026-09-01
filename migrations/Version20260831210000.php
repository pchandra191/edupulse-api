<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260831210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add reference_answer column to questions table for organizer-provided reference answers';
    }

    public function up(Schema $schema): void
    {
        $questions = $schema->getTable('questions');
        $questions->addColumn('reference_answer', 'text', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $questions = $schema->getTable('questions');
        $questions->dropColumn('reference_answer');
    }
}