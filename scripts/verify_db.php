<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=maintaingov', 'root', '');
echo "DB: maintaingov connected OK\n";
echo "Tables:\n";
foreach ($pdo->query('SHOW TABLES') as $row) {
    echo "  - " . $row[0] . "\n";
}
