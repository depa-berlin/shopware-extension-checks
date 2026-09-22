<?php

// Die Abhilfe zum Fall in der Schlecht-Vorlage: Erst die Spalte auf NOT NULL ziehen, dann den
// Schlüssel darüber legen. Danach darf die Prüfung nicht weiter mahnen — sonst wäre sie nach
// der Reparatur genauso rot wie davor, und niemand nähme sie mehr ernst.

$connection->executeStatement('
    ALTER TABLE `nachzug` ADD COLUMN `option_id` BINARY(16) NULL;
');
$connection->executeStatement('
    ALTER TABLE `nachzug` MODIFY COLUMN `option_id` BINARY(16) NOT NULL;
');
$connection->executeStatement('
    ALTER TABLE `nachzug` ADD UNIQUE KEY `uniq.nachzug.option` (`option_id`);
');
