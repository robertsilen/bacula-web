<?php

/**
 * Copyright (C) 2010-2022 Davide Franco
 *
 * This file is part of Bacula-Web.
 *
 * Bacula-Web is free software: you can redistribute it and/or modify it under the terms of the GNU
 * General Public License as published by the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * Bacula-Web is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
 * even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with Bacula-Web. If not, see
 * <https://www.gnu.org/licenses/>.
 */

class CDB
{
    private $connection;
    private $driver;

    /**
     * @param $dsn|null
     */
    public function __construct($dsn = null)
    {
        $user = null;
        $password  = null;

        // Open config file
        FileConfig::open(CONFIG_FILE);

        // Create PDO connection to database
        $catalog_id = $_SESSION['catalog_id'];

        $this->driver = FileConfig::get_Value('db_type', $catalog_id);

        if ($dsn === null) {
            $dsn = FileConfig::get_DataSourceName($catalog_id);
        }

        if ($this->driver != 'sqlite') {
            $user = FileConfig::get_Value('login', $catalog_id);
            $password  = FileConfig::get_Value('password', $catalog_id);
        }

        $this->connection = new PDO($dsn, $user, $password);

        // Set PDO connection options
        $this->connection->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->connection->setAttribute(PDO::ATTR_STATEMENT_CLASS, array('CDBResult', array($this)));

        // MySQL connection specific parameter
        if ($this->getDriverName() == 'mysql') {
            $this->connection->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    }

    /**
     * @return PDO
     */
    public function getDb() {
        return $this->connection;
    }

    // ==================================================================================
    // Function: 	getDriverName()
    // Parameters: 	none
    // Return:		driver name (eg: mysql, pgsql, sqlite, etc.)
    // ==================================================================================

    public function getDriverName()
    {
        return $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    // ==================================================================================
    // Function: 	getServerVersion()
    // Parameters: 	none
    // Return:		database server version
    // ==================================================================================

    public function getServerVersion()
    {
        $server_version = $this->connection->getAttribute(PDO::ATTR_SERVER_VERSION);
        $server_version = explode(':', $server_version);
        return $server_version[0];
    }
}
