<?php

class m261024_120000_debug_info_release_version extends CDbMigration
{
    public function up()
    {
        $this->addColumn('{{debuginfo}}', 'appversion_id', 'INTEGER NULL');
        $this->createIndex('debuginfo_release', '{{debuginfo}}', 'project_id, appversion_id');
    }

    public function down()
    {
        if ($this->dbConnection->driverName === 'sqlite') {
            echo "SQLite rollback requires restoring a database backup; no schema changes made.\n";
            return false;
        }
        $this->dropIndex('debuginfo_release', '{{debuginfo}}');
        $this->dropColumn('{{debuginfo}}', 'appversion_id');
    }
}
