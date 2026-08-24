<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add evaluation fields to exam_attempts table';
    }

    public function up(Schema $schema): void
    {
        $examAttemptsTable = $schema->getTable('exam_attempts');

        $examAttemptsTable->addColumn('score', 'integer', ['notnull' => false]);
        $examAttemptsTable->addColumn('max_marks', 'integer', ['notnull' => false]);
        $examAttemptsTable->addColumn('percentage', 'integer', ['notnull' => false]);
        $examAttemptsTable->addColumn('time_taken_seconds', 'integer', ['notnull' => false]);

        $examAttemptsTable->addIndex(['score'], 'idx_exam_attempt_score');
    }

    public function down(Schema $schema): void
    {
        $examAttemptsTable = $schema->getTable('exam_attempts');

        $examAttemptsTable->dropIndex('idx_exam_attempt_score');

        $examAttemptsTable->dropColumn('score');
        $examAttemptsTable->dropColumn('max_marks');
        $examAttemptsTable->dropColumn('percentage');
        $examAttemptsTable->dropColumn('time_taken_seconds');
    }
}
