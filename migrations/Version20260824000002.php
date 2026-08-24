<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exam_answers and exam_answer_options tables for exam answer storage';
    }

    public function up(Schema $schema): void
    {
        $examAnswersTable = $schema->createTable('exam_answers');

        $examAnswersTable->addColumn('id', 'uuid', ['notnull' => true]);
        $examAnswersTable->addColumn('exam_attempt_id', 'uuid', ['notnull' => true]);
        $examAnswersTable->addColumn('question_id', 'uuid', ['notnull' => true]);
        $examAnswersTable->addColumn('answer_text', 'text', ['notnull' => false]);
        $examAnswersTable->addColumn('selected_option_id', 'uuid', ['notnull' => false]);
        $examAnswersTable->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $examAnswersTable->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $examAnswersTable->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $examAnswersTable->setPrimaryKey(['id']);

        $examAnswersTable->addIndex(['exam_attempt_id'], 'idx_exam_answer_attempt');
        $examAnswersTable->addIndex(['question_id'], 'idx_exam_answer_question');
        $examAnswersTable->addIndex(['selected_option_id'], 'idx_exam_answer_selected_option');

        $examAnswersTable->addUniqueConstraint(['exam_attempt_id', 'question_id'], 'uniq_exam_answer_attempt_question');

        $examAnswersTable->addForeignKeyConstraint('exam_attempts', ['exam_attempt_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_ATTEMPT');
        $examAnswersTable->addForeignKeyConstraint('questions', ['question_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_QUESTION');
        $examAnswersTable->addForeignKeyConstraint('question_options', ['selected_option_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_EXAM_ANSWER_SELECTED_OPTION');

        $examAnswerOptionsTable = $schema->createTable('exam_answer_options');

        $examAnswerOptionsTable->addColumn('id', 'uuid', ['notnull' => true]);
        $examAnswerOptionsTable->addColumn('exam_answer_id', 'uuid', ['notnull' => true]);
        $examAnswerOptionsTable->addColumn('question_option_id', 'uuid', ['notnull' => true]);
        $examAnswerOptionsTable->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $examAnswerOptionsTable->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $examAnswerOptionsTable->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $examAnswerOptionsTable->setPrimaryKey(['id']);

        $examAnswerOptionsTable->addIndex(['exam_answer_id'], 'idx_exam_answer_option_answer');
        $examAnswerOptionsTable->addIndex(['question_option_id'], 'idx_exam_answer_option_option');

        $examAnswerOptionsTable->addUniqueConstraint(['exam_answer_id', 'question_option_id'], 'uniq_exam_answer_option_answer_option');

        $examAnswerOptionsTable->addForeignKeyConstraint('exam_answers', ['exam_answer_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_OPTION_ANSWER');
        $examAnswerOptionsTable->addForeignKeyConstraint('question_options', ['question_option_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_ANSWER_OPTION_OPTION');
    }

    public function down(Schema $schema): void
    {
        $examAnswerOptionsTable = $schema->getTable('exam_answer_options');
        $examAnswerOptionsTable->removeForeignKey('FK_EXAM_ANSWER_OPTION_OPTION');
        $examAnswerOptionsTable->removeForeignKey('FK_EXAM_ANSWER_OPTION_ANSWER');
        $schema->dropTable('exam_answer_options');

        $examAnswersTable = $schema->getTable('exam_answers');
        $examAnswersTable->removeForeignKey('FK_EXAM_ANSWER_SELECTED_OPTION');
        $examAnswersTable->removeForeignKey('FK_EXAM_ANSWER_QUESTION');
        $examAnswersTable->removeForeignKey('FK_EXAM_ANSWER_ATTEMPT');
        $schema->dropTable('exam_answers');
    }
}
