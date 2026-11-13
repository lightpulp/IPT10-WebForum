<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Turns the flat comment rows into a nested tree and calculates depth.
 * Depth is never stored: it is the number of parent links above a comment.
 */
final class CommentTree
{
    /** Key used in the grouped array for top-level comments (real IDs start at 1). */
    public const ROOT = 0;

    /**
     * Group rows by parent ID. Row order is kept, so siblings stay in ID order.
     *
     * @param  list<array{id:int|string,parent_id:int|string|null}> $rows
     * @return array<int, list<array>>
     */
    public static function groupByParent(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['parent_id'] === null ? self::ROOT : (int) $row['parent_id'];
            $grouped[$key][] = $row;
        }
        return $grouped;
    }

    /**
     * Walk the groups from the top level downwards (depth-first) and build nested nodes.
     *
     * Safety limits: at most $maxNodes comments are visited, nothing deeper than
     * $maxDepth is visited, and a comment is never visited twice, so bad data
     * cannot make the renderer loop or exhaust memory. Comments that cannot be
     * reached from the top level (e.g. a disconnected cycle) are not rendered.
     *
     * @param array<int, list<array>> $grouped
     * @param callable(array,int,list<array>):array $present builds one node from (row, depth, child nodes)
     * @return array{nodes:list<array>,count:int,truncated:bool}
     */
    public static function build(array $grouped, callable $present, int $maxNodes, int $maxDepth): array
    {
        $visited = [];
        $count = 0;
        $truncated = false;
        $nodes = self::walk($grouped, self::ROOT, 0, $present, $maxNodes, $maxDepth, $visited, $count, $truncated);
        return ['nodes' => $nodes, 'count' => $count, 'truncated' => $truncated];
    }

    private static function walk(
        array $grouped,
        int $parentKey,
        int $depth,
        callable $present,
        int $maxNodes,
        int $maxDepth,
        array &$visited,
        int &$count,
        bool &$truncated
    ): array {
        $nodes = [];
        foreach ($grouped[$parentKey] ?? [] as $row) {
            $id = (int) $row['id'];
            if (isset($visited[$id])) {
                continue;                       // already seen: ignore (cycle guard)
            }
            if ($count >= $maxNodes || $depth > $maxDepth) {
                $truncated = true;              // traversal limit reached
                continue;
            }
            $visited[$id] = true;
            $count++;
            $children = self::walk($grouped, $id, $depth + 1, $present, $maxNodes, $maxDepth, $visited, $count, $truncated);
            $nodes[] = $present($row, $depth, $children);
        }
        return $nodes;
    }
}
