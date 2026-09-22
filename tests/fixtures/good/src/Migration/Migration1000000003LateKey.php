<?php

// Die Abhilfe zum Fall in der Vorlage `bad`: Erst die Spalte auf NOT NULL ziehen, dann den
// Schlüssel darüber legen. Danach darf die Prüfung nicht weiter mahnen — sonst wäre sie nach
// der Reparatur genauso rot wie davor, und niemand nähme sie mehr ernst.

$connection->executeStatement('
    ALTER TABLE `later` ADD COLUMN `option_id` BINARY(16) NULL;
');
$connection->executeStatement('
    ALTER TABLE `later` MODIFY COLUMN `option_id` BINARY(16) NOT NULL;
');
$connection->executeStatement('
    ALTER TABLE `later` ADD UNIQUE KEY `uniq.later.option` (`option_id`);
');
