<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260827000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename mode column to attempt_mode in exam_attempts table to avoid PostgreSQL reserved word conflict';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempts');

        $table->dropColumn('mode');
        $table->addColumn('attempt_mode', 'string', ['length' => 10, 'notnull' => true, 'default' => 'ONLINE']);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempts');

        $table->dropColumn('attempt_mode');
        $table->addColumn('mode', 'string', ['length' => 10, 'notnull' => true, 'default' => 'ONLINE']);
    }
}
