<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add manual evaluation fields to exam_answers for descriptive question scoring';
    }

    public function up(Schema $schema): void
    {
        $examAnswers = $schema->getTable('exam_answers');

        if (!$examAnswers->hasColumn('marks_obtained')) {
            $examAnswers->addColumn('marks_obtained', 'integer', ['notnull' => false]);
        }

        if (!$examAnswers->hasColumn('requires_manual_evaluation')) {
            $examAnswers->addColumn('requires_manual_evaluation', 'boolean', ['notnull' => true, 'default' => true]);
        }

        if (!$examAnswers->hasColumn('is_correct')) {
            $examAnswers->addColumn('is_correct', 'boolean', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $examAnswers = $schema->getTable('exam_answers');

        if ($examAnswers->hasColumn('marks_obtained')) {
            $examAnswers->dropColumn('marks_obtained');
        }

        if ($examAnswers->hasColumn('requires_manual_evaluation')) {
            $examAnswers->dropColumn('requires_manual_evaluation');
        }

        if ($examAnswers->hasColumn('is_correct')) {
            $examAnswers->dropColumn('is_correct');
        }
    }
}
