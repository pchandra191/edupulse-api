<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260829000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add deleted_at column to exam_attempt_question_progress table';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempt_question_progress');

        if (!$table->hasColumn('deleted_at')) {
            $table->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        }
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_attempt_question_progress');

        if ($table->hasColumn('deleted_at')) {
            $table->dropColumn('deleted_at');
        }
    }
}
