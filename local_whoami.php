<?php
require __DIR__.'/config.php';
header('Content-Type: text/plain');
echo "Logged in? ".(is_logged_in() ? "yes" : "no").PHP_EOL;
print_r(current_user());
