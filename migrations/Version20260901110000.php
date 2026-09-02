<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allowed_duration_seconds and expires_at columns to exam_attempts for attempt-level timing';
    }

    public function up(Schema $schema): void
    {
        $examAttemptsTable = $schema->getTable('exam_attempts');
        $examAttemptsTable->addColumn('allowed_duration_seconds', 'integer', ['notnull' => false]);
        $examAttemptsTable->addColumn('expires_at', 'datetime_immutable', ['notnull' => false]);
        $examAttemptsTable->addIndex(['expires_at'], 'idx_exam_attempt_expires_at');
    }

    public function down(Schema $schema): void
    {
        $examAttemptsTable = $schema->getTable('exam_attempts');
        $examAttemptsTable->dropIndex('idx_exam_attempt_expires_at');
        $examAttemptsTable->dropColumn('expires_at');
        $examAttemptsTable->dropColumn('allowed_duration_seconds');
    }
}