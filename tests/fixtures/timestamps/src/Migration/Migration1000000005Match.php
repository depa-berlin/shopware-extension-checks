<?php

// Richtig: dieselbe Zahl im Namen wie in der Methode. Darf nicht gemeldet werden, sonst wäre
// nach der Reparatur alles genauso rot wie davor.

class Migration1000000005Match extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1000000005;
    }

    public function update(Connection $connection): void
    {
    }
}
