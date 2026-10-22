<?php

namespace App\Services;

use stdClass;

/**
 * Tree-comparison helpers used by the SOS Compare tool.
 *
 * Replaces two hot loops in the Compare Volt page:
 *  - flatten():    one recursive walk instead of json_encode → explode → 7× preg_grep → json_decode → flattenFsTree.
 *  - markNodes():  hash-indexed lookups instead of full-tree recursive find_node_by_attr per change.
 *
 * Mutates $origin / $target node trees in place (sets ->__status, appends nodes to dirs).
 */
class CompareTreeService
{
    /**
     * Fields stripped from each node before equality comparison. They change
     * between two reports of the same content (timestamps, monotonic ids,
     * checksums of timestamp-sensitive files) and are not meaningful diffs.
     */
    public const KILLED_FIELDS = ['tz', 'date', 'time', 'owner', 'group', 'sum', 'id'];

    /**
     * Root-level directories skipped by Compare. `sys` and `proc` are kernel
     * pseudo-filesystems with churn that's not actionable; `sos_logs` is the
     * sosreport's own collection log.
     */
    public const EXCLUDED_ROOT_DIRS = ['sys', 'proc', 'sos_logs'];

    /**
     * Walk the tree once and return a flat [path => normalized-node-array] map
     * suitable for $a != $b equality diffing.
     *
     * Mutates $contents in place: removes excluded root directories.
     */
    public static function flatten(object $contents): array
    {
        if (isset($contents->nodes[0]->nodes) && is_array($contents->nodes[0]->nodes)) {
            $contents->nodes[0]->nodes = array_values(array_filter(
                $contents->nodes[0]->nodes,
                fn ($node) => ! (
                    isset($node->name, $node->path, $node->type)
                    && in_array($node->name, self::EXCLUDED_ROOT_DIRS, true)
                    && $node->path === ''
                    && $node->type === 'd'
                )
            ));
        }

        $flat = [];
        if (! empty($contents->nodes) && is_array($contents->nodes)) {
            self::collect($contents->nodes, '', $flat);
        }

        return $flat;
    }

    /**
     * Build a "{$node->path}|{$node->name}" → &node index for fast lookup.
     * Index entries are references — mutations via the index reach into the tree.
     */
    public static function buildIndex(array &$nodes, array &$index = []): array
    {
        foreach ($nodes as &$node) {
            if (! isset($node->name)) {
                continue;
            }
            $key = ($node->path ?? '').'|'.$node->name;
            $index[$key] = &$node;
            if (isset($node->nodes) && is_array($node->nodes) && ! empty($node->nodes)) {
                self::buildIndex($node->nodes, $index);
            }
        }
        unset($node);

        return $index;
    }

    /**
     * Apply $status to all changes that match it: mark the node in $origin and,
     * for missing_left/missing_right, copy the node (with its parent chain if needed)
     * into $target so both halves of the UI render the same path layout.
     */
    public static function markNodes(
        array $changes,
        string $status,
        object $origin,
        object $target,
        array &$originIndex,
        array &$targetIndex
    ): void {
        foreach ($changes as $node) {
            if (! is_array($node) || ($node['__status'] ?? '') !== $status) {
                continue;
            }
            if (! isset($node['name'], $node['path'])) {
                continue;
            }

            $key = $node['path'].'|'.$node['name'];

            if ($status === 'different') {
                if (isset($originIndex[$key])) {
                    $originIndex[$key]->__status = $status;
                }
                if (isset($targetIndex[$key])) {
                    $targetIndex[$key]->__status = $status;
                }

                continue;
            }

            // missing_left / missing_right
            if (! isset($originIndex[$key])) {
                continue;
            }
            $originIndex[$key]->__status = $status;
            self::addMissingNode(
                $target,
                $originIndex[$key],
                $status,
                $targetIndex,
                $originIndex
            );
        }
    }

    protected static function collect(array $nodes, string $parentPath, array &$flat): void
    {
        foreach ($nodes as $node) {
            if (! isset($node->name, $node->type)) {
                continue;
            }

            // Build POSIX path. Root container has name='/' and lives at parentPath=''.
            // Its children come in with parentPath='/' and should not double up the slash.
            if ($node->name === '/') {
                $path = '/';
            } elseif ($parentPath === '' || $parentPath === '/') {
                $path = '/'.$node->name;
            } else {
                $path = $parentPath.'/'.$node->name;
            }

            $hasChildren = isset($node->nodes) && is_array($node->nodes) && ! empty($node->nodes);

            // Match flattenFsTree(): files, links, and empty dirs participate in the diff.
            if ($node->type === '-' || $node->type === 'l' || ($node->type === 'd' && ! $hasChildren)) {
                $clean = [];
                foreach (get_object_vars($node) as $key => $value) {
                    if ($key === 'nodes' || in_array($key, self::KILLED_FIELDS, true)) {
                        continue;
                    }
                    $clean[$key] = $value;
                }
                $flat[$path] = $clean;
            }

            if ($hasChildren) {
                self::collect($node->nodes, $path, $flat);
            }
        }
    }

    protected static function addMissingNode(
        object $target,
        object $node,
        string $status,
        array &$targetIndex,
        array &$originIndex
    ): bool {
        $maxDepth = 15;

        $key = ($node->path ?? '').'|'.$node->name;
        if (isset($targetIndex[$key])) {
            return true;
        }

        // Node sits at root level: attach directly to target's root container.
        if (($node->path ?? '') === '' && isset($target->nodes[0])) {
            $node->__status = $status;
            self::appendChild($target->nodes[0], $node, $targetIndex);

            return true;
        }

        [$parentName, $parentPath] = self::splitParent($node->path ?? '');
        $parentKey = $parentPath.'|'.$parentName;

        if (isset($targetIndex[$parentKey])) {
            $node->__status = $status;
            self::appendChild($targetIndex[$parentKey], $node, $targetIndex);

            return true;
        }

        // Parent dir is also missing in target: walk up, copying parent dirs from origin
        // until we find a grandparent that DOES exist in target (or hit the root).
        for ($i = 0; $i <= $maxDepth; $i++) {
            if (! isset($originIndex[$parentKey])) {
                return false;
            }
            $parentOrigin = $originIndex[$parentKey];
            $parentOrigin->__status = $status;

            // The walked-up parent itself sits at root level: attach to target root.
            if (($parentOrigin->path ?? '') === '' && isset($target->nodes[0])) {
                self::appendChild($target->nodes[0], $parentOrigin, $targetIndex);

                return true;
            }

            [$gpName, $gpPath] = self::splitParent($parentOrigin->path ?? '');
            $gpKey = $gpPath.'|'.$gpName;

            if (isset($targetIndex[$gpKey])) {
                self::appendChild($targetIndex[$gpKey], $parentOrigin, $targetIndex);

                return true;
            }

            if ($gpName === '') {
                break;
            }
            $parentName = $gpName;
            $parentPath = $gpPath;
            $parentKey = $gpKey;
        }

        return false;
    }

    protected static function appendChild(object $parent, object $child, array &$index): void
    {
        if (! isset($parent->nodes) || ! is_array($parent->nodes)) {
            $parent->nodes = [];
        }
        $parent->nodes[] = $child;
        self::indexSubtree($child, $index);
    }

    protected static function indexSubtree(object $node, array &$index): void
    {
        if (! isset($node->name)) {
            return;
        }
        $key = ($node->path ?? '').'|'.$node->name;
        $index[$key] = $node;
        if (isset($node->nodes) && is_array($node->nodes)) {
            foreach ($node->nodes as $child) {
                if ($child instanceof stdClass) {
                    self::indexSubtree($child, $index);
                }
            }
        }
    }

    /**
     * Split "a/b/c/" (the convention for node->path: parent path with trailing slash, '' for root)
     * into [name, parentPath]. The result obeys the same convention as the source data.
     */
    protected static function splitParent(string $path): array
    {
        $parts = explode('/', trim($path, '/'));
        $name = array_pop($parts) ?? '';
        $parentPath = implode('/', $parts);
        $parentPath = $parentPath === '' ? '' : $parentPath.'/';
        if (preg_match('|^/+$|', $parentPath)) {
            $parentPath = '';
        }

        return [$name, $parentPath];
    }
}
