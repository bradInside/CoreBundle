<?php

namespace Claroline\CoreBundle\Migrations\pdo_ibm;

use Doctrine\DBAL\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

/**
 * Auto-generated migration based on mapping information: modify it with caution
 *
 * Generation date: 2014/03/14 09:30:48
 */
class Version20140314093047 extends AbstractMigration
{
    public function up(Schema $schema)
    {
        $this->addSql("
            ALTER TABLE claro_tools 
            ADD COLUMN is_locked_for_admin SMALLINT NOT NULL 
            ADD COLUMN is_anonymous_excluded SMALLINT NOT NULL
        ");
        $this->addSql("
            ALTER TABLE claro_resource_type 
            ADD COLUMN defaultMask INTEGER NOT NULL
        ");
    }

    public function down(Schema $schema)
    {
        $this->addSql("
            ALTER TABLE claro_resource_type 
            DROP COLUMN defaultMask
        ");
        $this->addSql("
            ALTER TABLE claro_tools 
            DROP COLUMN is_locked_for_admin 
            DROP COLUMN is_anonymous_excluded
        ");
    }
}