<?php
declare(strict_types=1);

/**
 * Unit checks for the tree builder. No database or web server needed.
 * Run:  php tests/tree_test.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Support\CommentTree;

$failures = 0;
function check(string $name, bool $condition): void
{
    global $failures;
    echo ($condition ? '[pass] ' : '[FAIL] ') . $name . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

/** Presenter used in tests: keep only what we assert on. */
$cap = 8;
$present = static fn(array $row, int $depth, array $children): array => [
    'id'          => (int) $row['id'],
    'depth'       => $depth,
    'indent'      => min($depth, $cap),
    'hasChildren' => $children !== [],
    'children'    => $children,
];

/** Flatten nested nodes into [id => depth] in display order. */
function flatten(array $nodes): array
{
    $out = [];
    foreach ($nodes as $node) {
        $out[$node['id']] = $node['depth'];
        $out += flatten($node['children']);
    }
    return $out;
}

// --- The lab's seven comments ------------------------------------------------
$lab = [
    ['id' => 1, 'parent_id' => null],
    ['id' => 2, 'parent_id' => 1],
    ['id' => 3, 'parent_id' => 2],
    ['id' => 4, 'parent_id' => 1],
    ['id' => 5, 'parent_id' => null],
    ['id' => 6, 'parent_id' => 3],
    ['id' => 7, 'parent_id' => 6],
];
$tree = CommentTree::build(CommentTree::groupByParent($lab), $present, 1000, 100);
$flat = flatten($tree['nodes']);

check('lab data: all seven comments rendered', $tree['count'] === 7 && !$tree['truncated']);
check('lab data: depths are 0,1,2,3,4,1,0 in display order',
    array_values($flat) === [0, 1, 2, 3, 4, 1, 0] && array_keys($flat) === [1, 2, 3, 6, 7, 4, 5]);
check('lab data: comment 7 is displayed at depth 4', $flat[7] === 4);
check('lab data: two top-level comments (1 and 5)', count($tree['nodes']) === 2);
check('lab data: comment 1 has children, comment 5 has none',
    $tree['nodes'][0]['hasChildren'] === true && $tree['nodes'][1]['hasChildren'] === false);
check('lab data: Ben (2) and Dee (4) are siblings under comment 1',
    array_column($tree['nodes'][0]['children'], 'id') === [2, 4]);

// --- Indentation cap keeps the depth label accurate -------------------------
$chain = [['id' => 1, 'parent_id' => null]];
for ($i = 2; $i <= 13; $i++) {
    $chain[] = ['id' => $i, 'parent_id' => $i - 1];
}
$deep = CommentTree::build(CommentTree::groupByParent($chain), $present, 1000, 100);
$node = $deep['nodes'][0];
$found = [];
while ($node !== null) {
    $found[$node['id']] = [$node['depth'], $node['indent']];
    $node = $node['children'][0] ?? null;
}
check('cap: depth 12 keeps label 12 but indent 8', $found[13] === [12, 8]);
check('cap: depth 8 has indent 8, depth 7 has indent 7', $found[9] === [8, 8] && $found[8] === [7, 7]);

// --- Safety limits -------------------------------------------------------------
$limited = CommentTree::build(CommentTree::groupByParent($lab), $present, 3, 100);
check('traversal limit: stops at 3 comments and reports truncation',
    $limited['count'] === 3 && $limited['truncated'] === true);

$shallow = CommentTree::build(CommentTree::groupByParent($chain), $present, 1000, 4);
check('depth guard: nothing deeper than depth 4 is visited',
    max(flatten($shallow['nodes'])) === 4 && $shallow['truncated'] === true);

$cycle = [
    ['id' => 1, 'parent_id' => null],
    ['id' => 2, 'parent_id' => 3],   // 2 -> 3 -> 2 is a cycle with no path from the top level
    ['id' => 3, 'parent_id' => 2],
];
$cyc = CommentTree::build(CommentTree::groupByParent($cycle), $present, 1000, 100);
check('cycle: terminates; the unreachable cycle is not rendered', $cyc['count'] === 1);

$empty = CommentTree::build(CommentTree::groupByParent([]), $present, 1000, 100);
check('empty discussion: no nodes', $empty['nodes'] === [] && $empty['count'] === 0);

echo PHP_EOL . ($failures === 0 ? 'All tree checks passed.' : "$failures check(s) failed.") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
