<?php
require __DIR__ . '/config.php';
echo "DB_NAME=" . (isset($DB_NAME) ? $DB_NAME : '(unset)') . "\n";
foreach (get_included_files() as $f) {
    if (basename($f) === 'config.php') {
        echo "CONFIG_USED: $f\n";
    }
}
