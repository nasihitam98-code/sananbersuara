<?php

namespace App\Filament\Pages;

use App\Enums\DeviceKind;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\VoteStatus;
use App\Filament\Pages\Concerns\ShowsVoterProgress;
use App\Filament\Support\Workspace;
use App\Models\BallotVoter;
use App\Models\Device;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\Devices\DeviceManager;
use App\Services\Permits\TpsPauseService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Monitoring hari H Mode Resmi untuk Super Admin: satu kartu per RT berisi partisipasi, laptop meja, laptop bilik,
 * dan jeda TPS, sehingga RT yang bermasalah langsung terlihat. Tidak pernah menampilkan angka per calon.
 * Tindakan (token, lepas laptop, jeda) tetap di halaman Perangkat; daftar nama di halaman Partisipasi.
 */
class MonitoringPage extends Page
{
    // Daftar nama belum/sudah memilih (dulu menu Partisipasi): klik kartu RT untuk menyaring.
    use ShowsVoterProgress;

    protected string $view = 'filament.pages.monitoring-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Monitoring';

    protected static ?string $title = 'Monitoring Semua RT';

    protected static ?string $slug = 'monitoring';

    protected static ?int $navigationSort = 0;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows(ElectionMode::Resmi);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    public function election(): ?Election
    {
        return Election::query()
            ->where('mode', ElectionMode::Resmi)
            ->whereIn('status', [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused])
            ->latest()
            ->first();
    }

    /**
     * Satu baris per RT yang punya pemilih atau laptop.
     *
     * @return Collection<int, array{unit: Unit, done: int, total: int, percent: float, desk: string, booths: array{paired: int, slots: int, busy: int, offline: array<int, string>}, pause: ?string, problems: array<int, string>, tone: string}>
     */
    public function rows(): Collection
    {
        $election = $this->election();

        if ($election === null) {
            return collect();
        }

        app(DeviceManager::class)->ensureSlots($election);

        $devices = Device::query()->where('election_id', $election->id)->with(['unit', 'activePermit'])->get()->groupBy('unit_id');
        $progress = $this->progressByUnit($election);
        $registered = Voter::query()->where('is_active', true)->selectRaw('unit_id, count(*) as total')->groupBy('unit_id')->pluck('total', 'unit_id');
        $pauses = app(TpsPauseService::class);

        return Unit::query()->orderBy('sort')->get()
            ->filter(fn (Unit $unit): bool => $devices->has($unit->id) || $registered->has($unit->id))
            ->map(function (Unit $unit) use ($devices, $progress, $registered, $pauses, $election): array {
                $unitDevices = $devices->get($unit->id, collect());
                // Bisa ada beberapa laptop Meja per RT: tampilkan yang paling baru aktif.
                $desk = $unitDevices->filter(fn (Device $device): bool => $device->kind === DeviceKind::Meja)
                    ->sortByDesc(fn (Device $device): int => $device->isPaired() ? ($device->last_seen_at?->timestamp ?? 1) : 0)
                    ->first();
                $booths = $unitDevices->filter(fn (Device $device): bool => $device->kind === DeviceKind::Bilik);
                $deskState = $desk?->connectionState() ?? 'Belum tersambung';
                $offlineBooths = $booths->filter(fn (Device $device): bool => $device->connectionState() === 'Terputus')->map->code()->values()->all();
                $pairedBooths = $booths->filter->isPaired()->count();
                $total = $progress[$unit->id]['total'] ?? (int) ($registered[$unit->id] ?? 0);
                $done = $progress[$unit->id]['done'] ?? 0;
                $pause = $pauses->active($election, $unit->id);

                $problems = array_values(array_filter([
                    $total === 0 ? 'Belum ada pemilih' : null,
                    match ($deskState) {
                        'Belum tersambung', 'Dilepas' => 'Laptop meja belum dipasang',
                        'Terputus' => 'Laptop meja terputus',
                        default => null,
                    },
                    $pairedBooths === 0 ? 'Belum ada laptop bilik' : null,
                    $offlineBooths !== [] ? 'Bilik terputus: '.implode(', ', $offlineBooths) : null,
                ]));

                return [
                    'unit' => $unit,
                    'done' => $done,
                    'total' => $total,
                    'percent' => $total === 0 ? 0.0 : round($done * 100 / $total, 1),
                    'desk' => $deskState,
                    'booths' => [
                        'paired' => $pairedBooths,
                        'slots' => $booths->count(),
                        'busy' => $booths->filter(fn (Device $device): bool => in_array($device->connectionState(), ['Sedang dipakai', 'Dipesan'], true))->count(),
                        'offline' => $offlineBooths,
                    ],
                    'pause' => $pause?->reason_code->getLabel(),
                    'problems' => $problems,
                    'tone' => match (true) {
                        $problems !== [] => 'danger',
                        $pause !== null => 'warning',
                        default => 'success',
                    },
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{done: int, total: int, percent: float, problems: int, paused: int}
     */
    public function summary(Collection $rows): array
    {
        $done = (int) $rows->sum('done');
        $total = (int) $rows->sum('total');

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total === 0 ? 0.0 : round($done * 100 / $total, 1),
            'problems' => $rows->filter(fn (array $row): bool => $row['problems'] !== [])->count(),
            'paused' => $rows->whereNotNull('pause')->count(),
        ];
    }

    /**
     * Pemilih yang sudah menyelesaikan semua surat suara berhaknya, per RT (dari daftar pemilih beku saat Mulai).
     * Sebelum dimulai daftar beku belum ada; kartu memakai jumlah pemilih aktif.
     *
     * @return array<int, array{done: int, total: int}>
     */
    private function progressByUnit(Election $election): array
    {
        $ballotIds = $election->ballots()->pluck('id');
        $eligible = BallotVoter::query()->whereIn('ballot_id', $ballotIds)->whereNull('revoked_at')->get(['voter_id', 'ballot_id', 'unit_id']);

        if ($eligible->isEmpty()) {
            return [];
        }

        $voted = Vote::query()
            ->whereIn('ballot_id', $ballotIds)
            ->where('round_id', $election->currentRound()?->id)
            ->where('status', VoteStatus::Sah)
            ->whereIn('voter_id', $eligible->pluck('voter_id')->unique())
            ->get(['voter_id'])
            ->countBy('voter_id');

        return $eligible->groupBy('voter_id')
            ->map(fn (Collection $rows, int $voterId): array => ['unit_id' => $rows->first()->unit_id, 'done' => $rows->count() <= ($voted[$voterId] ?? 0)])
            ->groupBy('unit_id')
            ->map(fn (Collection $voters): array => ['done' => $voters->where('done', true)->count(), 'total' => $voters->count()])
            ->all();
    }
}
