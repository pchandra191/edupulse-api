<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260824000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create exam_submission_documents table for paper/offline exam answer uploads';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('exam_submission_documents');

        $table->addColumn('id', 'uuid', ['notnull' => true]);
        $table->addColumn('exam_attempt_id', 'uuid', ['notnull' => true]);
        $table->addColumn('uploaded_by_id', 'uuid', ['notnull' => true]);
        $table->addColumn('original_filename', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('stored_filename', 'string', ['length' => 255, 'notnull' => true]);
        $table->addColumn('mime_type', 'string', ['length' => 100, 'notnull' => true]);
        $table->addColumn('file_size', 'integer', ['notnull' => true]);
        $table->addColumn('page_count', 'integer', ['notnull' => false]);
        $table->addColumn('document_type', 'string', ['length' => 50, 'notnull' => true]);
        $table->addColumn('processing_status', 'string', ['length' => 50, 'notnull' => true]);
        $table->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $table->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);

        $table->setPrimaryKey(['id']);

        $table->addIndex(['exam_attempt_id'], 'idx_exam_submission_document_attempt');
        $table->addIndex(['uploaded_by_id'], 'idx_exam_submission_document_uploaded_by');
        $table->addIndex(['processing_status'], 'idx_exam_submission_document_status');

        $table->addForeignKeyConstraint('exam_attempts', ['exam_attempt_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_SUB_DOC_ATTEMPT');
        $table->addForeignKeyConstraint('users', ['uploaded_by_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_EXAM_SUB_DOC_UPLOADED_BY');
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable('exam_submission_documents');

        $table->removeForeignKey('FK_EXAM_SUB_DOC_UPLOADED_BY');
        $table->removeForeignKey('FK_EXAM_SUB_DOC_ATTEMPT');

        $schema->dropTable('exam_submission_documents');
    }
}
