<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260826000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add mode column to exam_attempts table for ONLINE/OFFLINE attempt support';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempts');

        $table->addColumn('mode', 'string', ['length' => 10, 'notnull' => true, 'default' => 'ONLINE']);

        $table->addIndex(['mode'], 'idx_exam_attempt_mode');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempts');

        $table->removeIndex('idx_exam_attempt_mode');

        $table->dropColumn('mode');
    }
}
