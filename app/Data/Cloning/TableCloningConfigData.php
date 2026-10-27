<?php

declare(strict_types=1);

namespace App\Data\Cloning;

final readonly class TableCloningConfigData
{
    /**
     * @param  list<ColumnCloningConfigData>  $columns
     * @param  list<string>  $notes  Config warnings for this table, reported with its run result
     */
    public function __construct(
        public string $tableName,
        public TableRowConfigData $rows,
        public array $columns,
        public array $notes = [],
    ) {}

    public function getColumn(string $name): ?ColumnCloningConfigData
    {
        foreach ($this->columns as $col) {
            if ($col->columnName === $name) {
                return $col;
            }
        }

        return null;
    }
}
