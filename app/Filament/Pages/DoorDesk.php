<?php

namespace App\Filament\Pages;

use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Enums\StaffRole;
use App\Filament\Pages\Concerns\InteractsWithElection;
use App\Filament\Support\InElectionMenu;
use App\Filament\Support\Workspace;
use App\Models\Attendee;
use App\Models\Unit;
use App\Services\AuditLogger;
use App\Services\Voting\AttendeeRegistrar;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Meja Pintu (Mode Dadakan): petugas mendata yang hadir; sistem membuat PIN yang tampil sekali.
 *
 * @property-read Schema $form
 */
class DoorDesk extends Page
{
    use InElectionMenu;

    public const ELECTION_MENU_SORT = 3;

    use InteractsWithElection;

    protected string $view = 'filament.pages.door-desk';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Meja Pintu';

    protected static ?string $title = 'Meja Pintu: Data Hadir & PIN';

    protected static ?string $slug = 'meja-pintu';

    protected static ?int $navigationSort = 1;

    public static function shouldRegisterNavigation(): bool
    {
        return Workspace::showsInElection(ElectionMode::Dadakan);
    }

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Hasil pendaftaran terakhir untuk ditampilkan sekali (nama, nomor, PIN).
     *
     * @var array{name: string, number: string, pin: string}|null
     */
    public ?array $issued = null;

    /**
     * Nama yang mirip dengan peserta yang sudah terdaftar, menunggu konfirmasi petugas.
     *
     * @var array<int, string>
     */
    public array $similarNames = [];

    protected static function allowedStaffRoles(): array
    {
        return [StaffRole::PetugasPintu, StaffRole::Panitia];
    }

    protected static function allowedStatuses(): array
    {
        return [ElectionStatus::Ready, ElectionStatus::Berlangsung, ElectionStatus::Paused];
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('name')
                    ->label('Nama lengkap yang hadir')
                    ->required()
                    ->minLength(3)
                    ->maxLength(120)
                    ->autocomplete(false)
                    ->autofocus()
                    ->extraInputAttributes(['class' => 'text-lg']),
                Select::make('unit_id')
                    ->label('RT (opsional)')
                    ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->placeholder('Tidak diisi'),
            ]);
    }

    public function register(): void
    {
        $election = $this->authorizedElection();
        $data = $this->form->getState();

        $similar = Attendee::query()
            ->where('election_id', $election->id)
            ->where('name_search', Attendee::normalizeForSearch($data['name']))
            ->get()
            ->map(fn (Attendee $attendee): string => "{$attendee->name} (No. {$attendee->displayNumber()})")
            ->all();

        if ($similar !== [] && $this->similarNames === []) {
            $this->similarNames = $similar;

            return;
        }

        try {
            $result = app(AttendeeRegistrar::class)->register($election, $data['name'], $data['unit_id'] ?? null, auth()->user());
        } catch (VotingException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if ($this->similarNames !== []) {
            app(AuditLogger::class)->log('attendee.duplicate_name_confirmed', $result['attendee'], $election, meta: ['similar' => count($this->similarNames)]);
        }

        $this->issued = [
            'name' => $result['attendee']->name,
            'number' => $result['attendee']->displayNumber(),
            'pin' => $result['pin'],
        ];
        $this->similarNames = [];
        $this->form->fill();
    }

    public function cancelDuplicate(): void
    {
        $this->similarNames = [];
    }

    public function acknowledge(): void
    {
        $this->issued = null;
    }

    /**
     * @return Collection<int, Attendee>
     */
    public function recentAttendees(): Collection
    {
        $election = $this->election();

        if ($election === null) {
            return collect();
        }

        return $election->attendees()->with('unit')->latest('id')->limit(10)->get();
    }
}
