<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exam_attempt_question_progress table for per-question progress tracking';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('exam_attempt_question_progress');

        $table->addColumn('id', 'uuid', ['notnull' => true]);
        $table->addColumn('exam_attempt_id', 'uuid', ['notnull' => true]);
        $table->addColumn('question_id', 'uuid', ['notnull' => true]);
        $table->addColumn('first_viewed_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('last_viewed_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('total_time_seconds', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('visit_count', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('navigation_count', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('first_answered_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('last_answered_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('answer_change_count', 'integer', ['notnull' => true, 'default' => 0]);
        $table->addColumn('status', 'string', ['length' => 30, 'notnull' => true]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['id']);
        $table->addForeignKeyConstraint('exam_attempts', ['exam_attempt_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('questions', ['question_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addUniqueIndex(['exam_attempt_id', 'question_id'], 'uniq_progress_attempt_question');
        $table->addIndex(['exam_attempt_id'], 'idx_progress_attempt');
        $table->addIndex(['question_id'], 'idx_progress_question');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('exam_attempt_question_progress');
    }
}
