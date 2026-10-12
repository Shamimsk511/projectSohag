<?php

namespace App\Services;

use App\Models\Party;
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
            ->when(trim($params['search']['value'] ?? ''), fn (Builder $query, string $term) => $query->search($term));

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
}
