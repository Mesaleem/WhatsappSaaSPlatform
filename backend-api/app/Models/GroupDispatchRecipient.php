<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Phase 5 fix P5-1 — the frozen recipient list of one group batch. See the
 * 2026_09_24_120000 migration.
 *
 * Written once, by the two group dispatchers, inside the reservation
 * transaction; read by the two group jobs. Nothing else writes it.
 */
class GroupDispatchRecipient extends Model
{
    protected $table = 'group_dispatch_recipients';

    protected $fillable = [
        'parent_dispatch_id',
        'account_id',
        'contact_group_member_id',
        'phone_number',
        'name',
    ];

    private const INSERT_CHUNK = 500;

    /**
     * The members a batch may be sent to, read in the reservation
     * transaction. Tenant-scoped twice: by group AND by account (the
     * (group_id, account_id) composite foreign key makes the two agree).
     *
     * @return Collection<int, ContactGroupMember>
     */
    public static function membersToReserve(ContactGroup $group): Collection
    {
        return ContactGroupMember::query()
            ->where('group_id', $group->id)
            ->where('account_id', $group->account_id)
            ->orderBy('id')
            ->get(['id', 'phone_number', 'name']);
    }

    /**
     * Persist exactly the members the reservation covered. Must be called
     * inside the same transaction as reserve() and recordGroupDispatchQueued().
     *
     * @param Collection<int, ContactGroupMember> $members
     */
    public static function freeze(MessageDispatchLog $parent, Collection $members): void
    {
        $now = now();

        foreach ($members->chunk(self::INSERT_CHUNK) as $chunk) {
            self::query()->insert($chunk->map(fn (ContactGroupMember $m) => [
                'parent_dispatch_id' => $parent->getKey(),
                'account_id' => $parent->account_id,
                'contact_group_member_id' => $m->id,
                'phone_number' => (string) $m->phone_number,
                'name' => $m->name,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());
        }
    }

    /**
     * What a group job iterates: the frozen list, each entry flagged
     * `removed` when that member has left the group since the reservation
     * (it is then NOT sent and resolves as a failure — refunded). A member
     * added since is never here, so never sent.
     *
     * Batches queued before this fix have no frozen rows; for those only,
     * the live membership is used, capped at the reserved count so they
     * can never send more than was paid for.
     *
     * @return Collection<int, object{id: int, phone_number: string, name: ?string, removed: bool}>
     */
    public static function recipientsFor(MessageDispatchLog $parent): Collection
    {
        $frozen = self::query()
            ->where('parent_dispatch_id', $parent->getKey())
            ->where('account_id', $parent->account_id)
            ->orderBy('id')
            ->get();

        if ($frozen->isEmpty()) {
            return ContactGroupMember::query()
                ->where('group_id', $parent->group_id)
                ->where('account_id', $parent->account_id)
                ->orderBy('id')
                ->limit(max(0, (int) $parent->recipient_count))
                ->get(['id', 'phone_number', 'name'])
                ->map(fn (ContactGroupMember $m) => (object) [
                    'id' => (int) $m->id, 'phone_number' => (string) $m->phone_number, 'name' => $m->name, 'removed' => false,
                ]);
        }

        $stillMembers = ContactGroupMember::query()
            ->where('group_id', $parent->group_id)
            ->where('account_id', $parent->account_id)
            ->pluck('id')
            ->flip();

        return $frozen->map(fn (self $r) => (object) [
            'id' => (int) $r->contact_group_member_id,
            'phone_number' => (string) $r->phone_number,
            'name' => $r->name,
            'removed' => ! $stillMembers->has($r->contact_group_member_id),
        ]);
    }
}
