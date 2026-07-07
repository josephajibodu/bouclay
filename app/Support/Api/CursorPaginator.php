<?php

namespace App\Support\Api;

use App\Exceptions\Api\InvalidPaginationCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Keyset pagination on (created_at DESC, id DESC) with publicId as the
 * external cursor label (Phase 10 — not sortable by public_id itself).
 */
class CursorPaginator
{
    /**
     * @param  Builder<Model>|Relation<Model, Model, *>  $query
     * @return array{items: list<Model>, pagination: array<string, mixed>}
     */
    public static function paginate(Builder|Relation $query, Request $request, string $publicIdColumn = 'public_id'): array
    {
        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }
        $limit = min(max((int) $request->query('limit', 25), 1), 100);
        $after = $request->query('after');
        $before = $request->query('before');

        if ($after !== null && $before !== null) {
            throw new InvalidPaginationCursor('Specify either after or before, not both.');
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        if (is_string($after) && $after !== '') {
            $anchor = (clone $query)->where($publicIdColumn, $after)->first();

            if ($anchor === null) {
                throw new InvalidPaginationCursor("Cursor anchor {$after} not found.");
            }

            $query->where(function (Builder $query) use ($anchor) {
                $query->where('created_at', '<', $anchor->created_at)
                    ->orWhere(function (Builder $query) use ($anchor) {
                        $query->where('created_at', '=', $anchor->created_at)
                            ->where('id', '<', $anchor->id);
                    });
            });
        }

        if (is_string($before) && $before !== '') {
            $anchor = (clone $query)->where($publicIdColumn, $before)->first();

            if ($anchor === null) {
                throw new InvalidPaginationCursor("Cursor anchor {$before} not found.");
            }

            $query->where(function (Builder $query) use ($anchor) {
                $query->where('created_at', '>', $anchor->created_at)
                    ->orWhere(function (Builder $query) use ($anchor) {
                        $query->where('created_at', '=', $anchor->created_at)
                            ->where('id', '>', $anchor->id);
                    });
            });

            $query->reorder()->orderBy('created_at')->orderBy('id');
        }

        $rows = $query->limit($limit + 1)->get();

        if (is_string($before) && $before !== '') {
            $rows = $rows->reverse()->values();
        }

        $hasMore = $rows->count() > $limit;

        if ($hasMore) {
            $rows = $rows->take($limit)->values();
        }

        /** @var list<Model> $items */
        $items = $rows->all();

        $pagination = ['hasMore' => $hasMore];

        if ($items !== []) {
            $first = $items[0];
            $last = $items[array_key_last($items)];

            if (isset($first->{$publicIdColumn})) {
                $pagination['previous'] = $first->{$publicIdColumn};
            }

            if (isset($last->{$publicIdColumn})) {
                $pagination['next'] = $last->{$publicIdColumn};
            }
        }

        return [
            'items' => $items,
            'pagination' => $pagination,
        ];
    }
}
