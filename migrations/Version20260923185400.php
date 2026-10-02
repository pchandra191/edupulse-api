<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration to add new lifecycle states and migrate existing analysis records
 * 
 * Mapping:
 * - CREATED, UPLOADED, EXTRACTED, NEEDS_REVIEW → DRAFT
 * - CONFIRMED → PUBLISHED
 * - ANALYZING → PROCESSING
 * - ANALYZED → DONE
 * - FAILED → PUBLISHED
 */
final class Version20260923185400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add new analysis lifecycle states and migrate existing records';
    }

    public function up(Schema $schema): void
    {
        // The status column already exists as VARCHAR(50), just need to update data
        $this->addSql("
            UPDATE analyses 
            SET status = CASE 
                WHEN status IN ('CREATED', 'UPLOADED', 'EXTRACTED', 'NEEDS_REVIEW') THEN 'DRAFT'
                WHEN status = 'CONFIRMED' THEN 'PUBLISHED'
                WHEN status = 'ANALYZING' THEN 'PROCESSING'
                WHEN status = 'ANALYZED' THEN 'DONE'
                WHEN status = 'FAILED' THEN 'PUBLISHED'
                ELSE status
            END
        ");
    }

    public function down(Schema $schema): void
    {
        // Reverse migration - map new states back to closest legacy equivalents
        $this->addSql("
            UPDATE analyses 
            SET status = CASE 
                WHEN status = 'DRAFT' THEN 'CREATED'
                WHEN status = 'PUBLISHED' THEN 'CONFIRMED'
                WHEN status = 'PROCESSING' THEN 'ANALYZING'
                WHEN status = 'DONE' THEN 'ANALYZED'
                WHEN status = 'ARCHIVED' THEN 'CREATED'
                ELSE status
            END
        ");
    }
}
