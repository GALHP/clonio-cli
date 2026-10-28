<?php

declare(strict_types=1);

namespace App\Services\Cloning;

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\ColumnCloningConfigData;
use App\Data\Cloning\KeyRemappingConfigData;
use App\Data\Cloning\KeyRemappingForeignKeyData;
use App\Data\Cloning\KeyRemappingTableData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Schema\DatabaseSchemaData;
use App\Data\Schema\TableSchemaData;
use Illuminate\Support\Facades\Log;

/**
 * Expands regex table keys in a cloning config against the real source schema.
 *
 * A key under `tables:` is either a literal table name or a slash-delimited
 * regex (`/…/`). Regex keys are expanded to the concrete source tables they
 * match, case-sensitively unless the pattern carries the `i` flag. A literal
 * name names the table with exactly that name; only when no source table has
 * it does it fall back to the one table that matches it case-insensitively
 * (none if several do). The same name resolution applies to every table
 * reference in the config: table keys, the `skip:` list and the key remapping
 * tables and foreign keys. When several entries match the same table, the last
 * one in YAML order wins for `rows`; column rules merge per column, a later
 * entry overriding only the columns it names. The result is a config whose
 * `tables` all carry concrete table names, in YAML order, so the rest of the
 * run (orchestrator, getTable(), dependency sort, audit) keeps working against
 * literal names.
 */
final readonly class TableConfigResolver
{
    public function resolve(CloningConfigData $config, DatabaseSchemaData $schema): CloningConfigData
    {
        $realNames = array_map(
            static fn (TableSchemaData $t): string => $t->name,
            $schema->tables,
        );
        // Each name is looked up once; the scan over all source tables is the costly part.
        $targets = [];
        $resolveName = function (string $name) use (&$targets, $realNames): string {
            return $targets[$name] ??= $this->literalTarget($name, $realNames) ?? $name; // @pest-mutate-ignore: CoalesceEqualToEqual
        };

        /** @var list<array{int, TableCloningConfigData}> $resolved [first matching entry, table] */
        $resolved = [];

        /** @var array<int, int> $matchedEntries */
        $matchedEntries = [];

        // 1. Expand every real table against the config entries (last match wins).
        foreach ($realNames as $realName) {
            /** @var list<int> $matching */
            $matching = [];

            foreach ($config->tables as $index => $entry) {
                $matches = $this->isRegex($entry->tableName)
                    ? preg_match($entry->tableName, $realName) === 1
                    : $resolveName($entry->tableName) === $realName;

                if ($matches) {
                    $matching[] = $index;
                    $matchedEntries[$index] = $index;
                }
            }

            if ($matching === []) {
                continue;
            }

            $resolved[] = [$matching[0], new TableCloningConfigData(
                tableName: $realName,
                rows: $config->tables[$matching[count($matching) - 1]]->rows,
                columns: $this->mergedColumns($config->tables, $matching),
            )];
        }

        // 2. Preserve literal entries that name no source table, so a mistyped
        //    or dropped table still reports NotFound as before. A regex that
        //    matches nothing was never a concrete table: drop it, but say so,
        //    as a typo would otherwise leave its tables out without a trace.
        foreach ($config->tables as $index => $entry) {
            if (isset($matchedEntries[$index])) {
                continue;
            }

            if ($this->isRegex($entry->tableName)) {
                Log::warning('table_pattern_unmatched', ['pattern' => $entry->tableName]);

                continue;
            }

            $resolved[] = [$index, $entry];
        }

        // Keep YAML order: a table sits where it is first mentioned. The sort is
        // stable, so tables first matched by the same entry keep schema order.
        usort($resolved, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $tables = array_map(static fn (array $r): TableCloningConfigData => $r[1], $resolved);

        return new CloningConfigData(
            version: $config->version,
            connectionName: $config->connectionName,
            options: $config->options,
            tables: $tables,
            keyRemapping: $this->resolveKeyRemapping($config->keyRemapping, $resolveName),
            skipTables: $this->resolveSkipTables($config->skipTables, $tables, $realNames, $resolveName),
        );
    }

    /**
     * The column rules of all entries matching a table, merged per column: a
     * later entry overrides only the columns it names, so e.g. a trailing
     * catch-all regex cannot drop an earlier anonymization rule. Columns keep
     * the order in which they are first mentioned.
     *
     * @param  list<TableCloningConfigData>  $entries
     * @param  list<int>  $matching  Indexes of the entries matching the table, in YAML order
     * @return list<ColumnCloningConfigData>
     */
    private function mergedColumns(array $entries, array $matching): array
    {
        $columns = [];

        foreach ($matching as $index) {
            foreach ($entries[$index]->columns as $column) {
                $columns[$column->columnName] = $column;
            }
        }

        return array_values($columns);
    }

    /**
     * Key remapping names its tables and foreign keys by literal name; resolve
     * them like table keys so they match the concrete tables.
     *
     * @param  callable(string): string  $resolveName
     */
    private function resolveKeyRemapping(?KeyRemappingConfigData $keyRemapping, callable $resolveName): ?KeyRemappingConfigData
    {
        if (! $keyRemapping instanceof KeyRemappingConfigData) {
            return null;
        }

        return new KeyRemappingConfigData(array_map(
            static fn (KeyRemappingTableData $t): KeyRemappingTableData => new KeyRemappingTableData(
                table: $resolveName($t->table),
                primaryKey: $t->primaryKey,
                strategy: $t->strategy,
                foreignKeys: array_map(
                    static fn (KeyRemappingForeignKeyData $fk): KeyRemappingForeignKeyData => new KeyRemappingForeignKeyData(
                        table: $resolveName($fk->table),
                        column: $fk->column,
                        selfReferential: $fk->selfReferential,
                    ),
                    $t->foreignKeys,
                ),
            ),
            $keyRemapping->tables,
        ));
    }

    /**
     * The tables to skip: the `skip:` list (regex entries expanded, literal
     * entries resolved) plus every table whose winning entry has
     * `rows.strategy: skip`.
     *
     * @param  list<string>  $skipList
     * @param  list<TableCloningConfigData>  $tables
     * @param  list<string>  $realNames
     * @param  callable(string): string  $resolveName
     * @return list<string>
     */
    private function resolveSkipTables(array $skipList, array $tables, array $realNames, callable $resolveName): array
    {
        $skip = [];

        foreach ($skipList as $entry) {
            $names = $this->isRegex($entry)
                ? array_filter($realNames, static fn (string $name): bool => preg_match($entry, $name) === 1)
                : [$resolveName($entry)];

            foreach ($names as $name) {
                $skip[$name] = $name;
            }
        }

        foreach ($tables as $table) {
            if ($table->rows->strategy === 'skip') {
                $skip[$table->tableName] = $table->tableName;
            }
        }

        return array_values($skip);
    }

    /**
     * The single source table a literal name matches case-insensitively, or
     * null when none or several do (e.g. `Users` and `users`). resolveName()
     * falls back to the name itself, so a table with exactly that name always
     * wins over a case-insensitive match.
     *
     * @param  list<string>  $realNames
     */
    private function literalTarget(string $name, array $realNames): ?string
    {
        $candidates = array_values(array_filter($realNames, static fn (string $real): bool => strcasecmp($name, $real) === 0));

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    private function isRegex(string $name): bool
    {
        return str_starts_with($name, '/');
    }
}
