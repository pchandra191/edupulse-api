<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260825120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create user profile tables: user_profiles, user_addresses, user_education, user_government_identifiers, user_bank_accounts, user_documents';
    }

    public function up(Schema $schema): void
    {
        $userProfiles = $schema->createTable('user_profiles');
        $userProfiles->addColumn('id', 'uuid', ['notnull' => true]);
        $userProfiles->addColumn('user_id', 'uuid', ['notnull' => true]);
        $userProfiles->addColumn('first_name', 'string', ['length' => 100, 'notnull' => false]);
        $userProfiles->addColumn('middle_name', 'string', ['length' => 100, 'notnull' => false]);
        $userProfiles->addColumn('last_name', 'string', ['length' => 100, 'notnull' => false]);
        $userProfiles->addColumn('date_of_birth', 'date', ['notnull' => false]);
        $userProfiles->addColumn('gender', 'string', ['length' => 20, 'notnull' => false]);
        $userProfiles->addColumn('blood_group', 'string', ['length' => 20, 'notnull' => false]);
        $userProfiles->addColumn('mobile', 'string', ['length' => 20, 'notnull' => false]);
        $userProfiles->addColumn('alternate_mobile', 'string', ['length' => 20, 'notnull' => false]);
        $userProfiles->addColumn('profile_photo_reference', 'string', ['length' => 500, 'notnull' => false]);
        $userProfiles->addColumn('nationality', 'string', ['length' => 100, 'notnull' => false]);
        $userProfiles->addColumn('marital_status', 'string', ['length' => 20, 'notnull' => false]);
        $userProfiles->addColumn('emergency_contact_name', 'string', ['length' => 200, 'notnull' => false]);
        $userProfiles->addColumn('emergency_contact_phone', 'string', ['length' => 20, 'notnull' => false]);
        $userProfiles->addColumn('emergency_contact_relationship', 'string', ['length' => 100, 'notnull' => false]);
        $userProfiles->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $userProfiles->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $userProfiles->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $userProfiles->setPrimaryKey(['id']);
        $userProfiles->addUniqueIndex(['user_id'], 'uniq_user_profile_user');
        $userProfiles->addIndex(['user_id'], 'idx_user_profile_user');

        $userAddresses = $schema->createTable('user_addresses');
        $userAddresses->addColumn('id', 'uuid', ['notnull' => true]);
        $userAddresses->addColumn('user_id', 'uuid', ['notnull' => true]);
        $userAddresses->addColumn('address_type', 'string', ['length' => 20, 'notnull' => true]);
        $userAddresses->addColumn('address_line1', 'string', ['length' => 255, 'notnull' => true]);
        $userAddresses->addColumn('address_line2', 'string', ['length' => 255, 'notnull' => false]);
        $userAddresses->addColumn('city', 'string', ['length' => 100, 'notnull' => true]);
        $userAddresses->addColumn('district', 'string', ['length' => 100, 'notnull' => false]);
        $userAddresses->addColumn('state', 'string', ['length' => 100, 'notnull' => true]);
        $userAddresses->addColumn('country', 'string', ['length' => 100, 'notnull' => true]);
        $userAddresses->addColumn('postal_code', 'string', ['length' => 20, 'notnull' => false]);
        $userAddresses->addColumn('is_primary', 'boolean', ['notnull' => true, 'default' => false]);
        $userAddresses->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $userAddresses->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $userAddresses->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $userAddresses->setPrimaryKey(['id']);
        $userAddresses->addIndex(['user_id'], 'idx_user_address_user');
        $userAddresses->addIndex(['address_type'], 'idx_user_address_type');
        $userAddresses->addIndex(['is_primary'], 'idx_user_address_primary');

        $userEducation = $schema->createTable('user_education');
        $userEducation->addColumn('id', 'uuid', ['notnull' => true]);
        $userEducation->addColumn('user_id', 'uuid', ['notnull' => true]);
        $userEducation->addColumn('institution_name', 'string', ['length' => 255, 'notnull' => true]);
        $userEducation->addColumn('education_level', 'string', ['length' => 30, 'notnull' => true]);
        $userEducation->addColumn('qualification', 'string', ['length' => 255, 'notnull' => true]);
        $userEducation->addColumn('specialization', 'string', ['length' => 255, 'notnull' => false]);
        $userEducation->addColumn('board_university', 'string', ['length' => 255, 'notnull' => true]);
        $userEducation->addColumn('start_date', 'date', ['notnull' => false]);
        $userEducation->addColumn('end_date', 'date', ['notnull' => false]);
        $userEducation->addColumn('result', 'string', ['length' => 50, 'notnull' => false]);
        $userEducation->addColumn('percentage', 'decimal', ['precision' => 5, 'scale' => 2, 'notnull' => false]);
        $userEducation->addColumn('grade', 'string', ['length' => 10, 'notnull' => false]);
        $userEducation->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $userEducation->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $userEducation->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $userEducation->setPrimaryKey(['id']);
        $userEducation->addIndex(['user_id'], 'idx_user_education_user');
        $userEducation->addIndex(['education_level'], 'idx_user_education_level');

        $userGovIds = $schema->createTable('user_government_identifiers');
        $userGovIds->addColumn('id', 'uuid', ['notnull' => true]);
        $userGovIds->addColumn('user_id', 'uuid', ['notnull' => true]);
        $userGovIds->addColumn('type', 'string', ['length' => 30, 'notnull' => true]);
        $userGovIds->addColumn('identifier', 'string', ['length' => 255, 'notnull' => true]);
        $userGovIds->addColumn('issuing_country', 'string', ['length' => 100, 'notnull' => false]);
        $userGovIds->addColumn('issuing_authority', 'string', ['length' => 255, 'notnull' => false]);
        $userGovIds->addColumn('issue_date', 'date', ['notnull' => false]);
        $userGovIds->addColumn('expiry_date', 'date', ['notnull' => false]);
        $userGovIds->addColumn('verification_status', 'string', ['length' => 20, 'notnull' => true]);
        $userGovIds->addColumn('verified_at', 'datetime_immutable', ['notnull' => false]);
        $userGovIds->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $userGovIds->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $userGovIds->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $userGovIds->setPrimaryKey(['id']);
        $userGovIds->addUniqueIndex(['user_id', 'type'], 'uniq_gov_id_user_type');
        $userGovIds->addIndex(['user_id'], 'idx_gov_id_user');
        $userGovIds->addIndex(['type'], 'idx_gov_id_type');
        $userGovIds->addIndex(['verification_status'], 'idx_gov_id_verification');

        $userBankAccounts = $schema->createTable('user_bank_accounts');
        $userBankAccounts->addColumn('id', 'uuid', ['notnull' => true]);
        $userBankAccounts->addColumn('user_id', 'uuid', ['notnull' => true]);
        $userBankAccounts->addColumn('account_holder_name', 'string', ['length' => 255, 'notnull' => true]);
        $userBankAccounts->addColumn('account_number', 'string', ['length' => 255, 'notnull' => true]);
        $userBankAccounts->addColumn('bank_name', 'string', ['length' => 255, 'notnull' => true]);
        $userBankAccounts->addColumn('branch_name', 'string', ['length' => 255, 'notnull' => false]);
        $userBankAccounts->addColumn('ifsc', 'string', ['length' => 20, 'notnull' => false]);
        $userBankAccounts->addColumn('account_type', 'string', ['length' => 20, 'notnull' => true]);
        $userBankAccounts->addColumn('is_primary', 'boolean', ['notnull' => true, 'default' => false]);
        $userBankAccounts->addColumn('verification_status', 'string', ['length' => 20, 'notnull' => true]);
        $userBankAccounts->addColumn('verified_at', 'datetime_immutable', ['notnull' => false]);
        $userBankAccounts->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $userBankAccounts->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $userBankAccounts->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $userBankAccounts->setPrimaryKey(['id']);
        $userBankAccounts->addIndex(['user_id'], 'idx_bank_account_user');
        $userBankAccounts->addIndex(['is_primary'], 'idx_bank_account_primary');
        $userBankAccounts->addIndex(['verification_status'], 'idx_bank_account_verification');

        $userDocuments = $schema->createTable('user_documents');
        $userDocuments->addColumn('id', 'uuid', ['notnull' => true]);
        $userDocuments->addColumn('user_id', 'uuid', ['notnull' => true]);
        $userDocuments->addColumn('document_type', 'string', ['length' => 30, 'notnull' => true]);
        $userDocuments->addColumn('file_name', 'string', ['length' => 255, 'notnull' => true]);
        $userDocuments->addColumn('mime_type', 'string', ['length' => 100, 'notnull' => true]);
        $userDocuments->addColumn('file_size', 'bigint', ['notnull' => true]);
        $userDocuments->addColumn('storage_reference', 'string', ['length' => 500, 'notnull' => true]);
        $userDocuments->addColumn('verification_status', 'string', ['length' => 20, 'notnull' => true]);
        $userDocuments->addColumn('verified_at', 'datetime_immutable', ['notnull' => false]);
        $userDocuments->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $userDocuments->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $userDocuments->addColumn('deleted_at', 'datetime_immutable', ['notnull' => false]);
        $userDocuments->setPrimaryKey(['id']);
        $userDocuments->addIndex(['user_id'], 'idx_user_document_user');
        $userDocuments->addIndex(['document_type'], 'idx_user_document_type');
        $userDocuments->addIndex(['verification_status'], 'idx_user_document_verification');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('user_documents');
        $schema->dropTable('user_bank_accounts');
        $schema->dropTable('user_government_identifiers');
        $schema->dropTable('user_education');
        $schema->dropTable('user_addresses');
        $schema->dropTable('user_profiles');
    }
}
