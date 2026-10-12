<?php

namespace App\Services;

use App\Models\Party;
use App\Support\Numerals;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Server-side DataTables query for the party list: search, filters, whitelisted ordering, paging.
 */
class PartyTableQuery
{
    /** DataTables column index => sortable column. Must match the table in admin/parties/index. */
    public const array ORDERABLE = [
        0 => 'party_code',
        1 => 'name',
        2 => 'phone',
        4 => 'status',
    ];

    /**
     * @param  array{draw?: int|string|null, start?: int|string|null, length?: int|string|null, search?: array{value?: string|null}, order?: list<array{column: int|string, dir: string}>, area_id?: int|string|null, status?: string|null}  $params
     * @return array{draw: int, recordsTotal: int, recordsFiltered: int, rows: Collection<int, Party>}
     */
    public function run(array $params): array
    {
        $filtered = Party::query()
            ->when($params['area_id'] ?? null, fn (Builder $query, int|string $areaId) => $query->where('area_id', $areaId))
            ->when($params['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when(trim($params['search']['value'] ?? ''), $this->search(...));

        $order = $params['order'][0] ?? ['column' => 0, 'dir' => 'desc'];

        return [
            'draw' => (int) ($params['draw'] ?? 0),
            'recordsTotal' => Party::count(),
            'recordsFiltered' => $filtered->count(),
            'rows' => $filtered->with('area')
                ->orderBy(self::ORDERABLE[(int) $order['column']], $order['dir'])
                ->orderBy('id')
                ->skip((int) ($params['start'] ?? 0))
                ->take((int) ($params['length'] ?? 25))
                ->get(),
        ];
    }

    /**
     * Match code, name, phone or NID. Bengali digits are accepted; LIKE wildcards are taken literally
     * via an explicit ESCAPE character ('!' because a backslash literal differs between MySQL and SQLite).
     *
     * @param  Builder<Party>  $query
     */
    private function search(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], Numerals::toAscii($term)).'%';

        $query->where(function (Builder $query) use ($like): void {
            foreach (['party_code', 'name', 'phone', 'national_id'] as $column) {
                $query->orWhereRaw("{$column} like ? escape '!'", [$like]);
            }
        });
    }
}
