<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260823040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add academic_year_id, academic_class_id columns and exam_session_sections join table for ExamSession';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable('exam_sessions');

        $table->addColumn('academic_year_id', 'uuid', ['notnull' => false]);
        $table->addColumn('academic_class_id', 'uuid', ['notnull' => false]);

        $table->addIndex(['academic_year_id'], 'idx_exam_session_academic_year');
        $table->addIndex(['academic_class_id'], 'idx_exam_session_academic_class');

        $joinTable = $schema->createTable('exam_session_sections');

        $joinTable->addColumn('exam_session_id', 'uuid', ['notnull' => true]);
        $joinTable->addColumn('section_id', 'uuid', ['notnull' => true]);

        $joinTable->setPrimaryKey(['exam_session_id', 'section_id']);

        $joinTable->addIndex(['section_id'], 'idx_exam_session_section_section');

        $joinTable->addForeignKeyConstraint('exam_sessions', ['exam_session_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_SESSION_SECTION_SESSION');
        $joinTable->addForeignKeyConstraint('sections', ['section_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_SESSION_SECTION_SECTION_ID');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_sessions');

        $table->removeIndex('idx_exam_session_academic_year');
        $table->removeIndex('idx_exam_session_academic_class');

        $table->dropColumn('academic_year_id');
        $table->dropColumn('academic_class_id');

        $joinTable = $schema->getTable('exam_session_sections');

        $joinTable->removeForeignKey('FK_EXAM_SESSION_SECTION_SECTION_ID');
        $joinTable->removeForeignKey('FK_EXAM_SESSION_SECTION_SESSION');

        $schema->dropTable('exam_session_sections');
    }
}
