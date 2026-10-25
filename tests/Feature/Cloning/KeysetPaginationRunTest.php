<?php

declare(strict_types=1);

use App\Data\Cloning\CloningConfigData;
use App\Data\Cloning\CloningOptionsData;
use App\Data\Cloning\RunResultData;
use App\Data\Cloning\TableCloningConfigData;
use App\Data\Cloning\TableRowConfigData;
use App\Data\ConnectionData;
use App\Enums\DatabaseConnectionType;
use App\Services\Cloning\CloningRunOrchestrator;
use App\Services\Schema\SchemaInspector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;

// Chunked reads against real SQLite databases: every row must arrive exactly once,
// whether the table is paged with a keyset cursor or falls back to LIMIT/OFFSET.

function keysetSqlitePath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'clonio_keyset_').'.sqlite';
    touch($path);

    return $path;
}

function keysetConnection(string $name, string $path): ConnectionData
{
    return new ConnectionData($name, DatabaseConnectionType::Sqlite, null, null, $path, null, null, '', false);
}

/**
 * 23 events with duplicate ranks and a NULL created_at on every third row. `events`
 * declares its key NOT NULL (keyset); `legacy_events` uses a plain rowid key,
 * which SQLite reports as nullable (LIMIT/OFFSET).
 */
function seedKeysetSource(): string
{
    $path = keysetSqlitePath();
    $pdo = new PDO('sqlite:'.$path);

    foreach (['events' => 'INTEGER PRIMARY KEY NOT NULL', 'legacy_events' => 'INTEGER PRIMARY KEY'] as $table => $key) {
        $pdo->exec(sprintf('CREATE TABLE %s (id %s, rank INTEGER NOT NULL, created_at TEXT)', $table, $key));

        for ($id = 1; $id <= 23; $id++) {
            $createdAt = $id % 3 === 0 ? 'NULL' : sprintf("'2026-01-%02d'", $id);
            $pdo->exec(sprintf('INSERT INTO %s VALUES (%d, %d, %s)', $table, $id, $id % 5, $createdAt));
        }
    }

    return $path;
}

/**
 * @return array{RunResultData, list<string>}
 */
function runKeyset(string $sourcePath, string $targetPath, string $table, TableRowConfigData $rows): array
{
    $source = keysetConnection('source', $sourcePath);
    $queries = [];
    Event::listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $result = resolve(CloningRunOrchestrator::class)->run(
        config: new CloningConfigData(
            version: '1',
            connectionName: 'source',
            options: new CloningOptionsData(4, false, false, false, false, 'en_US'),
            tables: [new TableCloningConfigData($table, $rows, [])],
        ),
        source: $source,
        target: keysetConnection('target', $targetPath),
        sourceSchema: resolve(SchemaInspector::class)->inspect($source),
        skipSchema: false,
        skipTables: [],
        onlyTables: [],
        onProgress: function (): void {},
    );

    return [$result, $queries];
}

/** @return list<int> */
function keysetTargetIds(string $path, string $table): array
{
    return array_map(intval(...), (new PDO('sqlite:'.$path))->query(sprintf('SELECT id FROM %s ORDER BY id', $table))->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * The ids a strategy should copy: sorted by (rank, id) in the given direction.
 *
 * @return list<int>
 */
function keysetExpectedIds(int $limit, bool $descending): array
{
    $ids = range(1, 23);
    usort($ids, static fn (int $a, int $b): int => [$a % 5, $a] <=> [$b % 5, $b]);
    if ($descending) {
        $ids = array_reverse($ids);
    }

    $ids = array_slice($ids, 0, $limit);
    sort($ids);

    return $ids;
}

it('copies every row exactly once with a keyset cursor', function (TableRowConfigData $rows, array $expectedIds, string $seek): void {
    $sourcePath = seedKeysetSource();
    $targetPath = keysetSqlitePath();

    [$result, $queries] = runKeyset($sourcePath, $targetPath, 'events', $rows);

    expect($result->success)->toBeTrue()
        ->and($result->totalRows)->toBe(count($expectedIds))
        ->and(keysetTargetIds($targetPath, 'events'))->toBe($expectedIds)
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, $seek)))->not->toBeEmpty()
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'OFFSET')))->toBeEmpty();

    @unlink($sourcePath);
    @unlink($targetPath);
})->with([
    'full' => [new TableRowConfigData('full', null, null), range(1, 23), 'WHERE ("id") > (?)'],
    'first by a column with duplicates' => [new TableRowConfigData('first', 10, 'rank'), keysetExpectedIds(10, descending: false), 'WHERE ("rank", "id") > (?, ?)'],
    'last by a column with duplicates' => [new TableRowConfigData('last', 7, 'rank'), keysetExpectedIds(7, descending: true), 'WHERE ("rank", "id") < (?, ?)'],
]);

it('copies every row exactly once when it falls back to LIMIT/OFFSET', function (string $table, TableRowConfigData $rows, int $expectedRows): void {
    $sourcePath = seedKeysetSource();
    $targetPath = keysetSqlitePath();

    [$result, $queries] = runKeyset($sourcePath, $targetPath, $table, $rows);

    expect($result->success)->toBeTrue()
        ->and($result->totalRows)->toBe($expectedRows)
        ->and(count(array_unique(keysetTargetIds($targetPath, $table))))->toBe($expectedRows)
        ->and(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'OFFSET')))->not->toBeEmpty();

    @unlink($sourcePath);
    @unlink($targetPath);
})->with([
    'nullable sort column' => ['events', new TableRowConfigData('first', 15, 'created_at'), 15],
    'rowid key SQLite reports as nullable' => ['legacy_events', new TableRowConfigData('full', null, null), 23],
]);
