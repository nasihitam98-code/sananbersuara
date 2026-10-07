<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\VoteDetailReason;
use App\Filament\Support\Reauthenticate;
use App\Filament\Support\Workspace;
use App\Models\Election;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vote;
use App\Models\Voter;
use App\Services\AuditLogger;
use App\Services\InternalNotifier;
use App\Services\Voting\VoteLinker;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * Detail Suara / Audit Pilihan (bagian 4.3): siapa memilih siapa, hanya Super Admin, hanya setelah DITUTUP,
 * dengan alasan dropdown + password ulang. Setiap pembukaan dan perubahan tampilan tercatat dan diberitahukan.
 */
class VoteDetailPage extends Page
{
    protected string $view = 'filament.pages.vote-detail-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = 'Lainnya';

    protected static ?int $navigationSort = 7;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::shows();
    }

    protected static ?string $navigationLabel = 'Detail Suara';

    protected static ?string $title = 'Detail Suara / Audit Pilihan';

    protected static ?string $slug = 'detail-suara';

    /** Diset hanya oleh aksi server setelah alasan + password; tidak bisa diubah dari browser. */
    #[Locked]
    public ?int $openedElectionId = null;

    #[Locked]
    public ?string $openedReason = null;

    public ?int $ballotFilter = null;

    public ?int $unitFilter = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * @return Collection<int, Election>
     */
    public function availableElections(): Collection
    {
        return Election::query()
            ->when(Workspace::current(), fn ($query, ElectionMode $mode) => $query->where('mode', $mode))
            ->whereIn('status', [ElectionStatus::Ditutup, ElectionStatus::Verifikasi, ElectionStatus::Published, ElectionStatus::Unpublished, ElectionStatus::Archived])
            ->whereNull('vote_links_destroyed_at')
            ->latest()
            ->get();
    }

    public function openedElection(): ?Election
    {
        return $this->openedElectionId === null ? null : $this->availableElections()->firstWhere('id', $this->openedElectionId);
    }

    public function openAction(): Action
    {
        return Action::make('open')
            ->label('Buka Detail Suara')
            ->icon('heroicon-o-lock-open')
            ->color('danger')
            ->modalHeading('Buka detail siapa memilih siapa?')
            ->modalDescription('Pembukaan ini dicatat di audit log dan diberitahukan ke Super Admin lain serta Ketua Panitia.')
            ->schema([
                Select::make('election')->label('Pemilihan')->options(fn (): array => $this->availableElections()->pluck('name', 'id')->all())->required(),
                Select::make('reason')->label('Alasan')->options(VoteDetailReason::class)->required()->live(),
                Textarea::make('note')->label('Catatan')->maxLength(500)
                    ->required(fn (Get $get): bool => in_array($get('reason'), [VoteDetailReason::Lainnya, VoteDetailReason::Lainnya->value], true)),
                Reauthenticate::field(),
            ])
            ->action(function (array $data): void {
                $election = $this->availableElections()->firstWhere('id', (int) $data['election']);
                abort_if($election === null, 403);

                $reason = $data['reason'] instanceof VoteDetailReason ? $data['reason'] : VoteDetailReason::from($data['reason']);

                $this->openedElectionId = $election->id;
                $this->openedReason = $reason->value;
                $this->ballotFilter = $election->ballots()->value('id');
                $this->unitFilter = null;

                app(AuditLogger::class)->log('vote_detail.opened', $election, $election, reasonCode: $reason->value, note: $data['note'] ?? null, meta: $this->viewMeta());

                /** @var User $user */
                $user = auth()->user();
                app(InternalNotifier::class)->notifySuperAdmins(
                    'Detail Suara dibuka',
                    "{$user->name} membuka detail siapa memilih siapa untuk \"{$election->name}\" dengan alasan: {$reason->getLabel()}.",
                    $user,
                );
            });
    }

    public function updatedBallotFilter(): void
    {
        $this->logView();
    }

    public function updatedUnitFilter(): void
    {
        $this->logView();
    }

    public function close(): void
    {
        $this->openedElectionId = null;
        $this->openedReason = null;
    }

    /**
     * Baris siapa memilih siapa. Mode Resmi lewat voter_id; Mode Dadakan lewat voter_link
     * (HMAC peserta + surat suara + putaran) yang dicocokkan ulang di server.
     *
     * @return Collection<int, array{unit: string, number: string, name: string, choice: string, round: int, valid: bool}>
     */
    public function rows(): Collection
    {
        $election = $this->openedElection();
        abort_unless(static::canAccess(), 403);

        if ($election === null || $this->ballotFilter === null) {
            return collect();
        }

        return $election->isDadakan() ? $this->dadakanRows($election) : $this->resmiRows($election);
    }

    /**
     * @return Collection<int, array{unit: string, number: string, name: string, choice: string, round: int, valid: bool}>
     */
    private function resmiRows(Election $election): Collection
    {
        return Vote::query()
            ->where('election_id', $election->id)
            ->where('ballot_id', $this->ballotFilter)
            ->whereNotNull('voter_id')
            ->when($this->unitFilter !== null, fn ($query) => $query->whereIn('voter_id', Voter::query()->where('unit_id', $this->unitFilter)->select('id')))
            ->with(['voter.unit', 'candidate', 'round'])
            ->get()
            ->map(fn (Vote $vote): array => [
                'unit' => $vote->voter->unit->name,
                'number' => (string) $vote->voter->voter_number,
                'name' => $vote->voter->name,
                'choice' => $vote->candidate->displayNumber().' · '.$vote->candidate->name,
                'round' => (int) $vote->round?->number,
                'valid' => $vote->status->value === 'SAH',
            ])
            ->sortBy(fn (array $row): string => $row['unit'].$row['name'])
            ->values();
    }

    /**
     * @return Collection<int, array{unit: string, number: string, name: string, choice: string, round: int, valid: bool}>
     */
    private function dadakanRows(Election $election): Collection
    {
        $linker = app(VoteLinker::class);
        $rounds = $election->rounds()->get();
        $attendees = $election->attendees()
            ->when($this->unitFilter !== null, fn ($query) => $query->where('unit_id', $this->unitFilter))
            ->with('unit')
            ->get();

        // Hitung ulang tautan setiap peserta untuk surat suara ini di setiap putaran.
        $byLink = [];
        foreach ($attendees as $attendee) {
            foreach ($rounds as $round) {
                $byLink[$linker->link($attendee, (int) $this->ballotFilter, $round->id)] = $attendee;
            }
        }

        return Vote::query()
            ->where('election_id', $election->id)
            ->where('ballot_id', $this->ballotFilter)
            ->whereNotNull('voter_link')
            ->with(['candidate', 'round'])
            ->get()
            ->filter(fn (Vote $vote): bool => isset($byLink[$vote->voter_link]))
            ->map(function (Vote $vote) use ($byLink): array {
                $attendee = $byLink[$vote->voter_link];

                return [
                    'unit' => $attendee->unit?->name ?? '-',
                    'number' => $attendee->displayNumber(),
                    'name' => $attendee->name,
                    'choice' => $vote->candidate->displayNumber().' · '.$vote->candidate->name,
                    'round' => (int) $vote->round?->number,
                    'valid' => $vote->status->value === 'SAH',
                ];
            })
            ->sortBy(fn (array $row): string => $row['number'])
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function unitOptions(): array
    {
        return Unit::query()->orderBy('sort')->pluck('name', 'id')->all();
    }

    private function logView(): void
    {
        $election = $this->openedElection();

        if ($election !== null) {
            app(AuditLogger::class)->log('vote_detail.viewed', $election, $election, reasonCode: $this->openedReason, meta: $this->viewMeta());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function viewMeta(): array
    {
        return ['ballot_id' => $this->ballotFilter, 'unit_id' => $this->unitFilter];
    }
}
