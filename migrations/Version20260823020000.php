<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exam_sessions table for ExamSession entity';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('exam_sessions');

        $table->addColumn('id', 'uuid', ['notnull' => true]);
        $table->addColumn('institute_id', 'uuid', ['notnull' => true]);
        $table->addColumn('question_paper_id', 'uuid', ['notnull' => true]);
        $table->addColumn('examination_id', 'uuid', ['notnull' => false]);
        $table->addColumn('exam_subject_id', 'uuid', ['notnull' => false]);
        $table->addColumn('created_by_id', 'uuid', ['notnull' => true]);
        $table->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('published_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('available_from', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('available_to', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('duration_override', 'integer', ['notnull' => false]);
        $table->addColumn('shuffle_questions', 'boolean', ['notnull' => true, 'default' => false]);
        $table->addColumn('show_results_mode', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('access_mode', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('allow_review', 'boolean', ['notnull' => true, 'default' => true]);
        $table->addColumn('total_marks', 'integer', ['notnull' => true]);
        $table->addColumn('status', 'string', ['length' => 20, 'notnull' => true]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['id']);

        $table->addIndex(['institute_id'], 'idx_exam_session_institute');
        $table->addIndex(['question_paper_id'], 'idx_exam_session_question_paper');
        $table->addIndex(['status'], 'idx_exam_session_status');
        $table->addUniqueIndex(['institute_id', 'title'], 'uniq_exam_session_institute_title');

        $table->addForeignKeyConstraint('institutes', ['institute_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_SESSION_INSTITUTE');
        $table->addForeignKeyConstraint('question_papers', ['question_paper_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_SESSION_QUESTION_PAPER');
        $table->addForeignKeyConstraint('examinations', ['examination_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EXAM_SESSION_EXAMINATION');
        $table->addForeignKeyConstraint('exam_subjects', ['exam_subject_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EXAM_SESSION_EXAM_SUBJECT');
        $table->addForeignKeyConstraint('users', ['created_by_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_SESSION_CREATED_BY');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_sessions');

        $table->removeForeignKey('FK_EXAM_SESSION_CREATED_BY');
        $table->removeForeignKey('FK_EXAM_SESSION_EXAM_SUBJECT');
        $table->removeForeignKey('FK_EXAM_SESSION_EXAMINATION');
        $table->removeForeignKey('FK_EXAM_SESSION_QUESTION_PAPER');
        $table->removeForeignKey('FK_EXAM_SESSION_INSTITUTE');

        $schema->dropTable('exam_sessions');
    }
}
