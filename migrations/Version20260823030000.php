<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exam_attempts table for ExamAttempt entity';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('exam_attempts');

        $table->addColumn('id', 'uuid', ['notnull' => true]);
        $table->addColumn('exam_session_id', 'uuid', ['notnull' => true]);
        $table->addColumn('student_id', 'uuid', ['notnull' => true]);
        $table->addColumn('status', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('started_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('submitted_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('auto_submitted_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['id']);

        $table->addIndex(['exam_session_id', 'student_id'], 'idx_exam_attempt_session_student');
        $table->addIndex(['student_id'], 'idx_exam_attempt_student');
        $table->addIndex(['status'], 'idx_exam_attempt_status');

        $table->addForeignKeyConstraint('exam_sessions', ['exam_session_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ATTEMPT_SESSION');
        $table->addForeignKeyConstraint('users', ['student_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ATTEMPT_STUDENT');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempts');

        $table->removeForeignKey('FK_EXAM_ATTEMPT_STUDENT');
        $table->removeForeignKey('FK_EXAM_ATTEMPT_SESSION');

        $schema->dropTable('exam_attempts');
    }
}
