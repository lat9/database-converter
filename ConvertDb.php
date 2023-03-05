<?php
// -----
// Part of the database collation conversion utility for Zen Cart.
//
class ConvertDb
{
    protected
        $link = false,

        $dbPrefix,
        $dbDatabase,

        $currentDbCharset,
        $currentDbCollation,
        $mySqlVersion,
        $tables = [],
        $messages = [],

        $timerOne = 0,
        $timerTwo = 0,

        $error = false,
        $errorMsg = '';

    // -----
    // The class-constructor determines the database credentials and, if found, performs the initial
    // database 'connection'.
    //
    // Note: If any error is found during the construction, the class will 'die'.
    //
    public function __construct()
    {
        // -----
        // Retrieve the database credentials from the /includes/local/configure.php and/or
        // /includes/configure.php.
        //
        $this->gatherRequiredDbConstants();

        // -----
        // Attach to the configured database, also determining the database's overall character-set.
        //
        $this->attachDatabase();

        // -----
        // Determine the tables and their associated fields in the configured database.
        //
        $this->getDbTablesAndFields();
    }

    public function __destruct()
    {
        if ($this->link !== false) {
            mysqli_close($this->link);
        }
    }

    public function getErrorMsg()
    {
        return $this->errorMsg;
    }

    public function getDbName()
    {
        return $this->dbDatabase;
    }

    public function getDbPrefix()
    {
        return $this->dbPrefix;
    }

    public function getCurrentDbCharset()
    {
        return $this->currentDbCharset;
    }

    public function getCurrentDbCollation()
    {
        return $this->currentDbCollation;
    }

    public function getDbMySqlVersion()
    {
        return $this->mySqlVersion;
    }

    public function getTablesAndFields()
    {
        return $this->tables;
    }

    public function getMessages()
    {
        return $this->messages;
    }

    // -----
    // Two timers are provided for the conversion tool's controller.  One is used
    // to time an overall process and the other is used to time an intermediate step.
    //
    public function startTimerOne()
    {
        $this->timerOne = $this->getMicrotime();
    }
    public function getTimerOne()
    {
        return $this->displayTimer($this->getMicrotime() - $this->timerOne);
    }
    public function startTimerTwo()
    {
        $this->timerTwo = $this->getMicrotime();
    }
    public function getTimerTwo()
    {
        return $this->displayTimer($this->getMicrotime() - $this->timerTwo);
    }
    protected function getMicrotime()
    {
        list($msec, $sec) = explode(' ', microtime());
        return $msec + $sec;
    }
    protected function displayTimer($s)
    {
        $m = floor($s / 60);
        $s = $s - $m * 60;
        $h = floor($m / 60);
        $m = $m - $h * 60;
        if ($h > 0) {
            $tfmt = $h . ':' . $m . ':' . number_format($s, 4);
        } elseif ($m > 0) {
            $tfmt = $m . ':' . number_format($s, 4);
        } else {
            $tfmt = number_format($s, 4);
        }
        return $tfmt;
    }

    public function getCollationsForCharset($charset)
    {
        if ($charset !== 'utf8' && $charset !== 'utf8mb4') {
            $this->errorMsg = "Unknown character-set ($charset); no collations are possible.";
            return false;
        }

        $sql = "SHOW COLLATION WHERE Charset = '$charset'";
        $query = $this->doQuery($sql);
        if ($query === false) {
            return false;
        }

        // -----
        // Gather the collations currently provided for the requested character-set.
        //
        $current_collations = [];
        while (($row = mysqli_fetch_assoc($query)) !== null) {
            $current_collations[] = $row['Collation'];
        }

        // -----
        // Set the preferred collations, depending on the new character set.
        //
        $collations = [];
        if ($charset === 'utf8mb4' && in_array('utf8mb4_unicode_520_ci', $current_collations)) {
            $collations[] = 'utf8mb4_unicode_520_ci';
        }
        $charset_unicode_ci = $charset . '_unicode_ci';
        if (in_array($charset_unicode_ci, $current_collations)) {
            $collations[] = $charset_unicode_ci;
        }
        $charset_general_ci = $charset . '_general_ci';
        if (in_array($charset_general_ci, $current_collations)) {
            $collations[] = $charset_general_ci;
        }
        $charset_bin = $charset . '_bin';
        if (in_array($charset_bin, $current_collations)) {
            $collations[] = $charset_bin;
        }

        if ($collations === []) {
            $this->errorMsg = "No suitable collations found for character-set &quot;$charset&quot;.";
            $collations = false;
        }

        return $collations;
    }

    public function getZcDatabaseVersion()
    {
        $table_name = $this->dbPrefix . 'project_version';
        $sql =
            "SELECT CONCAT(project_version_major, '.', project_version_minor) AS `version`
               FROM $table_name
              WHERE project_version_key = 'Zen-Cart Database'
              LIMIT 1";
        $query = $this->doQuery($sql);
        if ($query === false) {
            return false;
        }

        $version = mysqli_fetch_assoc($query);
        return ($version === false) ? false : $version['version'];
    }

    public function updateTableFieldsDefaults($table_name, $field_array)
    {
        $sql = "ALTER TABLE `$table_name`";
        foreach ($field_array as $field_name => $new_default) {
            $sql .= " ALTER COLUMN `$field_name` SET DEFAULT '$new_default',";
        }
        $sql = rtrim($sql, ',');

        return $this->doQuery($sql);
    }

    public function checkTableFieldsForZeroDates($table_name)
    {
        if (!isset($this->tables[$table_name])) {
            $this->errorMsg = "checkTableFieldsForZeroDates($table_name), unknown table.";
            return false;
        }

        if ($this->tables[$table_name]['info']['has_date_fields'] === false) {
            return 0;
        }

        $sql = "SELECT COUNT(*) AS zero_values_count FROM `$table_name` WHERE";
        foreach ($this->tables[$table_name]['fields'] as $field_name => $field_info) {
            switch ($field_info['Type']) {
                case 'date':
                    $sql .= " CAST($field_name AS CHAR(10)) = '0000-00-00' OR";
                    break;
                case 'datetime':
                    $sql .= " CAST($field_name AS CHAR(19)) = '0000-00-00 00:00:00' OR";
                    break;
                default:
                    break;
            }
        }
        $sql = rtrim($sql, ' OR');

        $query = $this->doQuery($sql);
        if ($query === false) {
            return false;
        }

        $retval = mysqli_fetch_assoc($query);
        return ($retval === false) ? $retval : (int)$retval['zero_values_count'];
    }

    public function correctTableFieldsZeroDateDefaults($table_name)
    {
        $this->messages = [];

        if (!isset($this->tables[$table_name])) {
            $this->errorMsg = "correctTableFieldsZeroDateDefaults($table_name), unknown table.";
            return false;
        }

        if ($this->tables[$table_name]['info']['has_date_fields'] === false) {
            return 0;
        }

        $change_count = 0;
        $fields_to_update = [];
        foreach ($this->tables[$table_name]['fields'] as $field_name => $field_info) {
            switch ($field_info['Type']) {
                case 'date':
                    if ($field_info['Default'] === "'0000-00-00'") {
                        $fields_to_update[$field_name] = '0001-01-01';
                    }
                    break;
                case 'datetime':
                    if ($field_info['Default'] === "'0000-00-00 00:00:00'") {
                        $fields_to_update[$field_name] = '0001-01-01 00:00:00';
                    }
                    break;
                default:
                    break;
            }
        }
        if ($fields_to_update !== []) {
            $result = $this->updateTableFieldsDefaults($table_name, $fields_to_update);
            if ($result === false) {
                return false;
            } else {
                foreach ($fields_to_update as $field_name => $new_default) {
                    $change_count++;
                    $this->messages[] = "<code>$table_name::$field_name</code>, default changed to '$new_default'.";
                }
            }
        }
        return $change_count;
    }

    public function correctZeroDateValuesInTable($table_name)
    {
        if (!isset($this->tables[$table_name])) {
            $this->errorMsg = "correctZeroDateValuesInTable($table_name), unknown table.";
            return false;
        }

        if ($this->tables[$table_name]['info']['has_date_fields'] === false) {
            return 0;
        }

        $zero_dates_corrected = 0;
        foreach ($this->tables[$table_name]['fields'] as $field_name => $field_info) {
            if ($field_info['Type'] === 'date') {
                $zero_value = '0000-00-00';
                $correct_value = '0001-01-01';
            } elseif ($field_info['Type'] === 'datetime') {
                $zero_value = '0000-00-00 00:00:00';
                $correct_value = '0001-01-01 00:00:00';
            } else {
                continue;
            }
            $sql =
                "UPDATE `$table_name`
                    SET `$field_name` = '$correct_value'
                  WHERE CAST($field_name AS CHAR(" . strlen($zero_value) . ")) = '$zero_value'";
            $ret = $this->doQuery($sql);
            if ($ret === false) {
                return false;
            }
            $rows_updated = mysqli_affected_rows($this->link);
            if ($rows_updated > 0) {
                $zero_dates_corrected += (int)$rows_updated;
            }
        }
        return $zero_dates_corrected;
    }

    public function updateTableCollations($table_name, $collation)
    {
        if (!isset($this->tables[$table_name])) {
            $this->errorMsg = "updateTableFieldsCollations($table_name, $collation), unknown table.";
            return false;
        }

        set_time_limit(120);

        if ($this->doQuery("REPAIR TABLE `$table_name`") === false) {
            return false;
        }

        // -----
        // Determine the 'character-set' to use for any fields' updates.  The
        // character set is the first portion of the to-be-applied collation, e.g.
        // for the collation utf8mb4_general_ci, the character set is utf8mb4.
        //
        $collation_charset = $this->getCharsetFromCollation($collation);

        // -----
        // Field-related collation updates are needed only if the table has character-based
        // fields.
        //
        $fields_updated = 0;
        if ($this->tables[$table_name]['info']['has_text_fields'] === true) {
            // -----
            // Determine the table's current indices, dropping them to start, since the
            // re-collation of any character-based field will cause the index to go invalid.
            //
            $indices = $this->getTableIndices($table_name);
            $sql = "ALTER TABLE `$table_name`";
            foreach ($indices as $index_name => $index_info) {
                $sql .= " DROP INDEX `$index_name`,";
            }
            $sql = rtrim($sql, ',');
            if ($this->doQuery($sql) === false) {
                return false;
            }

            // -----
            // Re-collate any various character-based fields within the table that don't currently
            // match the requested collation.
            //
            set_time_limit(120);
            $fields_updated = $this->updateTableFieldsCharsetAndCollation($table_name, $collation_charset, $collation);
            if ($fields_updated === false) {
                return false;
            }

            // -----
            // Re-build the table's indices.
            //
            set_time_limit(120);
            foreach ($indices as $index_name => $index_info) {
                $sql = "CREATE " . $index_info['unique'] . "INDEX `$index_name` ON `$table_name` (" . implode(', ', $index_info['columns']) . ")";
                if ($this->doQuery($sql) === false) {
                    return false;
                }
            }
        }

        // -----
        // Finally, if the table's current collation is different than that requested, update the table's
        // overall collation as well.
        //
        if ($this->tables[$table_name]['info']['Collation'] !== $collation) {
            $sql = "ALTER TABLE `$table_name` DEFAULT CHARACTER SET {$collation_charset} COLLATE {$collation}";
            if ($this->doQuery($sql) === false) {
                return false;
            }
            $fields_updated += 1000;
        }

        return $fields_updated;
    }

    public function updateDatabaseCollation($new_collation)
    {
        // -----
        // Determine the 'character-set' to use for any fields' updates.  The
        // character set is the first portion of the to-be-applied collation, e.g.
        // for the collation utf8mb4_general_ci, the character set is utf8mb4.
        //
        $new_charset = $this->getCharsetFromCollation($new_collation);

        // -----
        // Update the current database's character-set and collation.
        //
        $sql = "ALTER DATABASE {$this->dbDatabase} DEFAULT CHARACTER SET $new_charset COLLATE $new_collation";
        return ($this->doQuery($sql) === false) ? false : true;
    }

    // -----
    // A character set is the first portion of the to-be-applied collation, e.g.
    // for the collation utf8mb4_general_ci, the character set is utf8mb4.
    //
    protected function getCharsetFromCollation($collation)
    {
        $collation_split = explode('_', $collation);

        return $collation_split[0];
    }

    protected function getTableIndices($table_name)
    {
        $query = $this->doQuery("SHOW INDEX FROM `$table_name`");
        if ($query === false) {
            return false;
        }

        $indices = [];
        while (($row = mysqli_fetch_assoc($query)) !== null) {
            $index_name = $row['Key_name'];
            if ($index_name === 'PRIMARY') {
                continue;
            }
            if (isset($indices[$index_name])) {
                $indices[$index_name]['columns'][] = $row['Column_name'] . (($row['Sub_part'] === null) ? '' : "({$row['Sub_part']})");
            } else {
                $indices[$index_name] = [
                    'unique' => ($row['Non_unique'] === '1') ? '' : 'UNIQUE ',
                    'columns' => [
                        $row['Column_name'] . (($row['Sub_part'] === null) ? '' : "({$row['Sub_part']})"),
                    ],
                ];
            }
        }

        return $indices;
    }

    protected function updateTableFieldsCharsetAndCollation($table_name, $new_charset, $new_collation)
    {
        $fields_updated = 0;
        $sql_binary = '';
        $sql_convert = '';
        foreach ($this->tables[$table_name]['fields'] as $field_name => $field_info) {
            if (!isset($field_info['alter_parameters']) || ($field_info['Charset'] === $new_charset && $field_info['Collation'] === $new_collation)) {
                continue;
            }

            $alter_parameters = $field_info['alter_parameters'];
            $fields_updated++;

            // -----
            // 'enum' field types don't get converted to binary!  See https://codex.wordpress.org/Converting_Database_Character_Sets
            // for details.
            //
            if ($alter_parameters['bin_type'] !== false) {
                $sql_binary .= " MODIFY `$field_name` {$alter_parameters['bin_type']} {$alter_parameters['allow_null']} {$alter_parameters['default']},";
            }
            $sql_convert  .= " MODIFY `$field_name` {$alter_parameters['text_type']} CHARACTER SET $new_charset COLLATE $new_collation {$alter_parameters['allow_null']} {$alter_parameters['default']},";
        }

        if ($fields_updated !== 0) {
            $sql_binary = rtrim($sql_binary, ',');
            if ($this->doQuery("ALTER TABLE `$table_name` $sql_binary") === false) {
                return false;
            }
           $sql_convert = rtrim($sql_convert, ',');
            if ($this->doQuery("ALTER TABLE `$table_name` $sql_convert") === false) {
                return false;
            }
        }
        return $fields_updated;
    }

    protected function gatherRequiredDbConstants()
    {
        // -----
        // The collection of constants present in the configure.php file(s) that are required for
        // this tool's processing.
        //
        $database_constants = [
            'DB_PREFIX',
            'DB_CHARSET',
            'DB_SERVER',
            'DB_SERVER_USERNAME',
            'DB_SERVER_PASSWORD',
            'DB_DATABASE',
        ];

        // -----
        // If a /local (developer-override) version of the configure.php is found, load that
        // first, setting a flag that indicates that any 'base' configure.php will require
        // parsing for database constants to prevent any duplicate-constant warnings/errors
        // for more recent versions of PHP.
        //
        $local_configure_loaded = false;
        if (file_exists('includes/local/configure.php')) {
            $local_configure_loaded = true;
            require 'includes/local/configure.php';
        }

        // -----
        // If the 'base' configure.php is found ...
        //
        if (file_exists('includes/configure.php')) {
            // -----
            // ... and a local-override wasn't previously loaded, simply load that file.  Otherwise,
            // load the file and use the definitions present there, if not already defined.
            //
            if ($local_configure_loaded === false) {
                require 'includes/configure.php';
            } else {
                $base_configure_file = file_get_contents('includes/configure.php');
                if ($base_configure_file === false) {
                    die('Error reading <code>includes/configure.php</code>.');
                }

                foreach ($database_constants as $define_name) {
                    if (defined($define_name)) {
                        continue;
                    }
                    if (preg_match("~define\('$define_name',\s*'(\S*)'\)~", $base_configure_file, $matches) !== 1) {
                        continue;
                    }
                    define($define_name, $matches[1]);
                }
            }
        }

        // -----
        // Now, check to ensure that all of the database-related credentials are actually defined; if not,
        // nothing further to be done.
        //
        for ($i = 0, $n = count($database_constants); $i < $n; $i++) {
            if (defined($database_constants[$i])) {
                unset($database_constants[$i]);
            }
        }
        if ($database_constants !== []) {
            die('Missing one or more required database constants: <code>' . implode('</code> <code>', $database_constants) . '</code>.');
        }
    }

    protected function attachDatabase()
    {
        $this->link = mysqli_connect(DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD);
        if ($this->link === false) {
            die('Error connecting to MySQL database ' . DB_SERVER . ': ' . mysqli_connect_errno() . ': ' . mysql_connect_error() . '<br>' . "\n");
        }

        $this->mySqlVersion = mysqli_get_server_info($this->link);
        if (version_compare($this->mySqlVersion, '4.1.1', '<=') === true) {
            die('This MySQL version (' . $this->mySqlVersion . ') is not supported!!!');
        }

        $this->dbDatabase = DB_DATABASE;
        $this->dbPrefix = DB_PREFIX;

        if (mysqli_select_db($this->link, $this->dbDatabase) === false) {
            die('Error selecting database "' . $this->dbDatabase . '": ' . mysqli_errno($this->link) . ': ' . mysqli_error($this->link) . '<br>' . "\n");
        }

        // -----
        // Determine the database's current overall character set (latin1 vs. utf8 vs. utf8mb4).
        //
        $query = $this->doQuery("SHOW VARIABLES LIKE 'character_set_database'");
        if ($query === false) {
            die('Unable to determine selected database character set: ' . $this->errorMsg);
        }
        $database_charset = mysqli_fetch_assoc($query);
        $this->currentDbCharset = $database_charset['Value'];

        // -----
        // Determine the database's current overall collation.
        //
        $query = $this->doQuery("SHOW VARIABLES LIKE 'collation_database'");
        if ($query === false) {
            die('Unable to determine selected database collation: ' . $this->errorMsg);
        }
        $database_collation = mysqli_fetch_assoc($query);
        $this->currentDbCollation = $database_collation['Value'];
    }

    protected function getDbTablesAndFields()
    {
        // -----
        // This is the 'base' query used to gather a table's fields' information.  The %s
        // is filled in (via sprintf) with the current table's name.
        //
        $fields_sql_base = 
            "SELECT `COLUMN_NAME` AS `Field`,
                    `COLUMN_TYPE` AS `Type`,
                    `CHARACTER_SET_NAME` AS `Charset`,
                    `COLLATION_NAME` AS `Collation`,
                    `IS_NULLABLE` AS `Null`,
                    `COLUMN_KEY` AS `Key`,
                    `COLUMN_DEFAULT` AS `Default`,
                    `EXTRA` AS `Extra`
               FROM `information_schema`.`COLUMNS`
              WHERE TABLE_NAME = '%s' 
                AND TABLE_SCHEMA = '{$this->dbDatabase}'";

        $query_tables = $this->doQuery("SHOW TABLE STATUS FROM `{$this->dbDatabase}`");
        while (($table = mysqli_fetch_assoc($query_tables)) !== null) {
            if (!preg_match('@^' . $this->dbPrefix . '@', $table['Name'])) {
                continue;
            }
            $table_name = $table['Name'];
            unset(
                $table['Name'],
                $table['Engine'],
                $table['Version'],
                $table['Row_format'],
                $table['Avg_row_length'],
                $table['Max_data_length'],
                $table['Data_free'],
                $table['Check_time'],
                $table['Checksum'],
                $table['Max_index_length'],
                $table['Create_options'],
                $table['Temporary']
            );
            $table['has_date_fields'] = false;
            $table['has_text_fields'] = false;
            $this->tables[$table_name] = [
                'info' => $table,
                'fields' => [],
            ];

            $fields = $this->doQuery(sprintf($fields_sql_base, $table_name));
            while (($row = mysqli_fetch_assoc($fields)) !== null) {
                $field_name = $row['Field'];
                unset($row['Field']);

                $row['known_field_type'] = true;
                $field_type = strtolower($row['Type']);

                if ($field_type === 'date' || $field_type === 'datetime') {
                    $this->tables[$table_name]['info']['has_date_fields'] = true;
                } elseif ($row['Charset'] !== null) {
                    $allow_null = (strtoupper($row['Null']) === 'YES') ? '' : 'NOT NULL';
                    $default_value = ($allow_null === '') ? 'DEFAULT NULL' : '';
                    if (isset($row['Default']) && $row['Default'] !== 'NULL' && $row['Default'] !== null) {
                        $default = trim($row['Default'], "'");
                        if (strpos($field_type, 'char') !== false || strpos($field_type, 'enum') === 0) {
                            $default_value = "'{$default}'";
                        }
                        $default_value = "DEFAULT {$default_value}";
                    }
                    $alter_parameters = [
                        'allow_null' => $allow_null,
                        'default' => $default_value,
                    ];

                    switch ($field_type) {
                        case 'tinytext':
                            $alter_parameters['bin_type'] = 'tinyblob';
                            $alter_parameters['text_type'] = 'tinytext';
                            break;
                        case 'text':
                            $alter_parameters['bin_type'] = 'blob';
                            $alter_parameters['text_type'] = 'text';
                            break;
                        case 'mediumtext':
                            $alter_parameters['bin_type'] = 'mediumblob';
                            $alter_parameters['text_type'] = 'mediumtext';
                            break;
                        case 'longtext':
                            $alter_parameters['bin_type'] = 'longblob';
                            $alter_parameters['text_type'] = 'longtext';
                            break;
                        default:
                            if (strpos($field_type, 'enum(') === 0) {
                                $alter_parameters['bin_type'] = false;
                                $alter_parameters['text_type'] = $row['Type'];
                            } elseif (preg_match("/^(varchar|char)\((\d+)\)$/", $field_type, $matches) !== 1) {
                                $row['known_field_type'] = false;
                                unset($alter_parameters);
                            } else {
                                $alter_parameters['bin_type'] = str_replace('char', '', $matches[1]) . 'binary(' . $matches[2] . ')';
                                $alter_parameters['text_type'] = $field_type;
                            }
                            break;
                    }
                    if (isset($alter_parameters)) {
                        $row['alter_parameters'] = $alter_parameters;
                        $this->tables[$table_name]['info']['has_text_fields'] = true;
                    }
                }

                $this->tables[$table_name]['fields'][$field_name] = $row;
            }
        }
    }

    protected function doQuery($sql)
    {
        $ret = mysqli_query($this->link, $sql);
        if ($ret === false) {
            $this->errorMsg = '<code>' . $sql . '</code><br>' . mysqli_errno($this->link) . ': ' . mysqli_error($this->link) . '<br>';
        } else {
            $this->errorMsg = '';
        }
        return $ret;
    }
}
