<?php

namespace App\Services\Results;

use App\Enums\ElectionStatus;
use App\Enums\ReportStatus;
use App\Models\AuditLog;
use App\Models\Election;
use App\Models\OfficialReport;
use App\Models\Outcome;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Voting\ResultsCalculator;
use App\Services\Voting\VotingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Berita acara (bagian 9A): draf dibuat dari data, dicetak dan ditandatangani, lalu disahkan.
 * Setelah disahkan isinya terkunci (checksum); perubahan hanya lewat versi baru dengan alasan.
 * Tidak pernah memuat siapa memilih siapa.
 */
class OfficialReportService
{
    /**
     * @var array<int, ElectionStatus>
     */
    private const EDITABLE_STATUSES = [ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Unpublished];

    public function __construct(
        private ResultSlots $slots,
        private ResultsCalculator $calculator,
        private AuditLogger $audit,
    ) {}

    public function current(Election $election, ?Unit $scope): ?OfficialReport
    {
        return OfficialReport::query()
            ->where('election_id', $election->id)
            ->where('unit_id', $scope?->id)
            ->where('status', '!=', ReportStatus::Digantikan)
            ->latest('version')
            ->first();
    }

    public function createDraft(Election $election, ?Unit $scope, User $actor, ?string $reason = null): OfficialReport
    {
        if (! in_array($election->status, self::EDITABLE_STATUSES, true)) {
            throw VotingException::invalidState('Berita acara hanya bisa dibuat setelah pemilihan ditutup dan sebelum dipublikasikan.');
        }

        $report = DB::transaction(function () use ($election, $scope, $actor, $reason): OfficialReport {
            Election::query()->whereKey($election->id)->lockForUpdate()->first();
            $current = $this->current($election, $scope);

            if ($current?->status === ReportStatus::Disahkan && blank($reason)) {
                throw VotingException::invalidState('Berita acara sudah disahkan. Versi baru wajib disertai alasan perubahan.');
            }

            $current?->forceFill(['status' => ReportStatus::Digantikan])->save();

            $version = (int) OfficialReport::query()
                ->where('election_id', $election->id)
                ->where('unit_id', $scope?->id)
                ->max('version') + 1;

            $content = (string) json_encode($this->buildContent($election, $scope), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            $report = new OfficialReport;
            $report->forceFill([
                'election_id' => $election->id,
                'unit_id' => $scope?->id,
                'version' => $version,
                'number' => $this->number($election, $scope, $version),
                'status' => ReportStatus::Draft,
                'content' => $content,
                'checksum' => hash('sha256', $content),
                'revision_reason' => $reason,
                'created_by' => $actor->id,
            ])->save();

            return $report;
        });

        $this->audit->log('report.draft_created', $report, $election, note: $reason, meta: [
            'scope' => $report->scopeLabel(),
            'version' => $report->version,
            'checksum' => $report->checksum,
        ], actor: $actor);

        return $report;
    }

    public function ratify(OfficialReport $report, User $actor): void
    {
        $election = $report->election;

        if (! in_array($election->status, self::EDITABLE_STATUSES, true)) {
            throw VotingException::invalidState('Berita acara hanya bisa disahkan sebelum hasil dipublikasikan.');
        }

        DB::transaction(function () use ($report, $actor): void {
            $locked = OfficialReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReportStatus::Draft) {
                throw VotingException::invalidState('Hanya draf terbaru yang bisa disahkan.');
            }

            if (! $locked->checksumIsValid()) {
                throw VotingException::invalidState('Checksum berita acara tidak cocok. Buat draf baru.');
            }

            $locked->forceFill([
                'status' => ReportStatus::Disahkan,
                'ratified_by' => $actor->id,
                'ratified_at' => Carbon::now(),
            ])->save();

            $report->setRawAttributes($locked->getAttributes(), true);
        });

        $this->audit->log('report.ratified', $report, $election, meta: [
            'scope' => $report->scopeLabel(),
            'version' => $report->version,
            'checksum' => $report->checksum,
        ], actor: $actor);
    }

    /**
     * Penetapan berubah: berita acara lingkup itu harus dibuat ulang dan disahkan ulang.
     */
    public function invalidate(Election $election, ?Unit $scope, User $actor): void
    {
        $current = $this->current($election, $scope);

        if ($current === null) {
            return;
        }

        $current->forceFill(['status' => ReportStatus::Digantikan])->save();

        $this->audit->log('report.invalidated', $current, $election, note: 'Penetapan hasil berubah', meta: [
            'scope' => $current->scopeLabel(),
            'version' => $current->version,
        ], actor: $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildContent(Election $election, ?Unit $scope): array
    {
        $round = $election->currentRound();
        $participation = $this->calculator->participation($election, $round);
        $ballots = [];

        foreach ($this->slots->slotsInScope($election, $scope) as $slot) {
            $tally = $round === null ? null : $this->calculator->tally($slot['ballot'], $round, $slot['unit']);
            $outcome = Outcome::query()
                ->where('ballot_id', $slot['ballot']->id)
                ->where('unit_id', $slot['unit']?->id)
                ->with('candidates')
                ->latest('id')
                ->first();

            $ballots[] = [
                'title' => $slot['ballot']->title.($slot['unit'] !== null ? ' — '.$slot['unit']->name : ''),
                'round' => $round?->number,
                'valid' => $tally['valid'] ?? 0,
                'cancelled' => $tally['cancelled'] ?? 0,
                'tie_at_top' => $tally['tie_at_top'] ?? false,
                'candidates' => collect($tally['candidates'] ?? [])->map(fn (array $row): array => [
                    'number' => $row['candidate']->displayNumber(),
                    'name' => $row['candidate']->name,
                    'withdrawn' => $row['candidate']->status->value === 'MUNDUR',
                    'votes' => $row['votes'],
                    'percent' => $row['percent'],
                    'rank' => $row['rank'],
                ])->all(),
                'outcome' => $outcome === null ? null : [
                    'label' => $outcome->label(),
                    'candidates' => $outcome->candidates->map(fn ($candidate): string => $candidate->displayNumber().' · '.$candidate->name)->all(),
                    'note' => $outcome->note,
                ],
            ];
        }

        return [
            'election' => [
                'name' => $election->name,
                'mode' => $election->mode->getLabel(),
                'started_at' => $election->started_at?->format('d-m-Y H:i'),
                'closed_at' => $election->closed_at?->format('d-m-Y H:i'),
            ],
            'scope' => $scope?->name ?? 'Keseluruhan',
            'generated_at' => Carbon::now()->format('d-m-Y H:i:s'),
            'participation' => [
                'registered' => $participation['attendees'],
                'voted' => $participation['voted'],
                'percent' => $participation['percent'],
                'assisted' => $participation['assisted'],
                'late' => $participation['late'],
                'headcount' => $election->setting('headcount'),
            ],
            'ballots' => $ballots,
            'incidents' => $this->incidents($election),
        ];
    }

    /**
     * Catatan kejadian otomatis dari audit log (angka saja, tanpa identitas pemilih).
     *
     * @return array<string, int>
     */
    private function incidents(Election $election): array
    {
        $count = fn (string $action): int => AuditLog::query()->where('election_id', $election->id)->where('action', $action)->count();

        return [
            'Gelombang dibuka' => $count('wave.opened'),
            'Perpanjangan waktu' => $count('wave.extended'),
            'Pulihkan hak pilih' => $count('attendee.voting_right_restored'),
            'PIN terkunci' => $count('attendee.pin_locked'),
            'Nama kembar dikonfirmasi petugas' => $count('attendee.duplicate_name_confirmed'),
            'Penayangan hasil' => $count('results.revealed'),
        ];
    }

    private function number(Election $election, ?Unit $scope, int $version): string
    {
        $year = ($election->closed_at ?? Carbon::now())->format('Y');
        $code = $scope === null ? 'UMUM' : 'RT'.$scope->code;

        return sprintf('BA/%s/%03d/%s/V%d', $year, $election->id, $code, $version);
    }
}
