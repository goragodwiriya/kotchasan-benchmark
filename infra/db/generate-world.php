<?php
/**
 * Generates the deterministic `world` dataset (10,000 rows) as SQL.
 * Seeded with mt_srand(42) so every machine produces identical data.
 *
 * Usage: php generate-world.php > 02-world.sql
 */
mt_srand(42);

echo "USE benchmark;\n";
echo "INSERT INTO world (id, randomNumber) VALUES\n";

$rows = [];
for ($id = 1; $id <= 10000; $id++) {
    $rows[] = sprintf('(%d, %d)', $id, mt_rand(1, 10000));
}
echo implode(",\n", $rows), ";\n";
