<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exam_answers and exam_answer_options tables for ExamAnswer';
    }

    public function up(Schema $schema): void
    {
        $examAnswers = $schema->createTable('exam_answers');

        $examAnswers->addColumn('id', 'uuid', ['notnull' => true]);
        $examAnswers->addColumn('exam_attempt_id', 'uuid', ['notnull' => true]);
        $examAnswers->addColumn('question_id', 'uuid', ['notnull' => true]);
        $examAnswers->addColumn('selected_option_id', 'uuid', ['notnull' => false]);
        $examAnswers->addColumn('answer_text', 'text', ['notnull' => false]);
        $examAnswers->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $examAnswers->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $examAnswers->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $examAnswers->setPrimaryKey(['id']);

        $examAnswers->addIndex(['exam_attempt_id'], 'idx_exam_answer_attempt');
        $examAnswers->addIndex(['question_id'], 'idx_exam_answer_question');
        $examAnswers->addIndex(['selected_option_id'], 'idx_exam_answer_selected_option');

        $examAnswers->addUniqueIndex(['exam_attempt_id', 'question_id'], 'uniq_exam_answer_attempt_question');

        $examAnswers->addForeignKeyConstraint('exam_attempts', ['exam_attempt_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_ATTEMPT');
        $examAnswers->addForeignKeyConstraint('questions', ['question_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_QUESTION');
        $examAnswers->addForeignKeyConstraint('question_options', ['selected_option_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EXAM_ANSWER_SELECTED_OPTION');

        $examAnswerOptions = $schema->createTable('exam_answer_options');

        $examAnswerOptions->addColumn('exam_answer_id', 'uuid', ['notnull' => true]);
        $examAnswerOptions->addColumn('question_option_id', 'uuid', ['notnull' => true]);

        $examAnswerOptions->setPrimaryKey(['exam_answer_id', 'question_option_id']);

        $examAnswerOptions->addIndex(['exam_answer_id'], 'idx_exam_answer_option_answer');
        $examAnswerOptions->addIndex(['question_option_id'], 'idx_exam_answer_option_option');

        $examAnswerOptions->addForeignKeyConstraint('exam_answers', ['exam_answer_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_OPTION_ANSWER');
        $examAnswerOptions->addForeignKeyConstraint('question_options', ['question_option_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_OPTION_OPTION');
    }

    public function down(Schema $schema): void
    {
        $examAnswerOptions = $schema->getTable('exam_answer_options');
        $examAnswerOptions->removeForeignKey('FK_EXAM_ANSWER_OPTION_OPTION');
        $examAnswerOptions->removeForeignKey('FK_EXAM_ANSWER_OPTION_ANSWER');
        $schema->dropTable('exam_answer_options');

        $examAnswers = $schema->getTable('exam_answers');
        $examAnswers->removeForeignKey('FK_EXAM_ANSWER_SELECTED_OPTION');
        $examAnswers->removeForeignKey('FK_EXAM_ANSWER_QUESTION');
        $examAnswers->removeForeignKey('FK_EXAM_ANSWER_ATTEMPT');
        $schema->dropTable('exam_answers');
    }
}
