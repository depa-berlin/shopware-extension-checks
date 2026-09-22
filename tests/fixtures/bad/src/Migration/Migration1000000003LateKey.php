<?php

// Der Schlüssel kommt SPÄTER als die Spalte — der Fall, an dem die Store-Prüfung wirklich hing.
// `property_group_option_id` entsteht NULL-fähig in Migration1000000001Bad.php; wer nur in
// diese Datei hier schaut, sieht davon nichts.

$connection->executeStatement('
    ALTER TABLE `preset_option`
        ADD UNIQUE KEY `uniq.preset_option.nachtraeglich` (`preset_id`, `property_group_option_id`);
');
