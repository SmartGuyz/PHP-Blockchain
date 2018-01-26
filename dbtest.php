<?php
$cSQLite3 = new SQLite3('database/phpbc.db');

$oSqlCheck = $cSQLite3->query('SELECT * FROM `blockchain`');
var_dump($oSqlCheck->fetchArray(SQLITE3_ASSOC));

# test
?>