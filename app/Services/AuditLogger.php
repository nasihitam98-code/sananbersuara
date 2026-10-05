<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Election;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * Menulis audit log append-only dengan hash berantai.
 *
 * Jangan pernah mengirim PIN, token, password, atau pilihan kandidat ke $meta.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(
        string $action,
        ?Model $subject = null,
        ?Election $election = null,
        ?string $reasonCode = null,
        ?string $note = null,
        array $meta = [],
        ?User $actor = null,
        string $actorType = 'user',
    ): AuditLog {
        $actor ??= Auth::user();

        if ($actor === null && $actorType === 'user') {
            $actorType = 'system';
        }

        return DB::transaction(function () use ($action, $subject, $election, $reasonCode, $note, $meta, $actor, $actorType): AuditLog {
            $head = DB::table('audit_chain_heads')->where('id', 1)->lockForUpdate()->first();

            $entry = [
                'occurred_at' => Carbon::now()->format('Y-m-d H:i:s.u'),
                'actor_type' => $actorType,
                'actor_id' => $actor?->getKey(),
                'actor_label' => $actor?->email,
                'action' => $action,
                'subject_type' => $subject === null ? null : class_basename($subject),
                'subject_id' => $subject === null ? null : (string) ($subject->getAttribute('public_id') ?? $subject->getKey()),
                'election_id' => $election?->getKey(),
                'reason_code' => $reasonCode,
                'note' => $note,
                'meta' => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
                'ip_address' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255) ?: null,
                'prev_hash' => $head?->last_hash,
            ];

            $entry['hash'] = static::hashEntry($entry);

            $id = DB::table('audit_logs')->insertGetId($entry);

            DB::table('audit_chain_heads')->where('id', 1)->update([
                'last_hash' => $entry['hash'],
                'last_audit_log_id' => $id,
            ]);

            return AuditLog::query()->findOrFail($id);
        });
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function hashEntry(array $entry): string
    {
        $fields = [
            'occurred_at', 'actor_type', 'actor_id', 'actor_label', 'action', 'subject_type', 'subject_id',
            'election_id', 'reason_code', 'note', 'meta', 'ip_address', 'user_agent', 'prev_hash',
        ];

        $payload = collect($fields)->map(fn (string $field): string => (string) ($entry[$field] ?? ''))->implode('|');

        return hash('sha256', $payload);
    }

    /**
     * Memeriksa ulang seluruh rantai. Mengembalikan id entri pertama yang rusak, atau null jika utuh.
     */
    public function findFirstBrokenEntry(): ?int
    {
        $previousHash = null;
        $brokenId = null;

        DB::table('audit_logs')->orderBy('id')->chunk(500, function ($rows) use (&$previousHash, &$brokenId): bool {
            foreach ($rows as $row) {
                $entry = (array) $row;
                $entry['occurred_at'] = Carbon::parse($row->occurred_at)->format('Y-m-d H:i:s.u');

                if ($row->prev_hash !== $previousHash || static::hashEntry($entry) !== $row->hash) {
                    $brokenId = $row->id;

                    return false;
                }

                $previousHash = $row->hash;
            }

            return true;
        });

        return $brokenId;
    }
}
