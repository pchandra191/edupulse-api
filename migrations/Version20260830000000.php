<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260830000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exam_attempt_security_events table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('exam_attempt_security_events');

        $table->addColumn('id', 'uuid', ['notnull' => true]);
        $table->addColumn('exam_attempt_id', 'uuid', ['notnull' => true]);
        $table->addColumn('exam_session_id', 'uuid', ['notnull' => true]);
        $table->addColumn('student_id', 'uuid', ['notnull' => true]);
        $table->addColumn('event_type', 'string', ['length' => 30, 'notnull' => true]);
        $table->addColumn('count', 'integer', ['notnull' => true, 'default' => 1]);
        $table->addColumn('occurred_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('exam_attempts', ['exam_attempt_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('exam_sessions', ['exam_session_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('users', ['student_id'], ['id'], ['onDelete' => 'CASCADE']);

        $table->addIndex(['exam_attempt_id'], 'idx_security_event_attempt');
        $table->addIndex(['exam_session_id'], 'idx_security_event_session');
        $table->addIndex(['student_id'], 'idx_security_event_student');
        $table->addIndex(['event_type'], 'idx_security_event_type');
        $table->addIndex(['occurred_at'], 'idx_security_event_occurred');
        $table->addIndex(['exam_attempt_id', 'occurred_at'], 'idx_security_event_attempt_occurred');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempt_security_events');

        if ($table !== null) {
            $schema->dropTable('exam_attempt_security_events');
        }
    }
}
