<?php

namespace App\Filament\Pages;

use App\Enums\DeviceKind;
use App\Enums\DeviceReleaseReason;
use App\Enums\ElectionMode;
use App\Enums\ElectionStatus;
use App\Filament\Support\Reauthenticate;
use App\Models\Device;
use App\Models\Election;
use App\Models\User;
use App\Services\Devices\DeviceManager;
use App\Services\Voting\VotingException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Perangkat & Perangkat Terhubung (Super Admin, bagian 5.2 dan 5.4):
 * token Meja per RT, status semua Meja dan Bilik, peringatan perangkat terputus.
 */
class DevicesPage extends Page
{
    protected string $view = 'filament.pages.devices-page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Hari H';

    protected static ?string $navigationLabel = 'Perangkat';

    protected static ?string $title = 'Perangkat Terhubung';

    protected static ?string $slug = 'perangkat';

    protected static ?int $navigationSort = 5;

    /**
     * @var array{desk: string, token: string}|null
     */
    public ?array $issuedToken = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
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
     * @return Collection<string, Collection<int, Device>>
     */
    public function devicesByUnit(): Collection
    {
        $election = $this->election();

        if ($election === null) {
            return collect();
        }

        app(DeviceManager::class)->ensureSlots($election);

        return Device::query()
            ->where('election_id', $election->id)
            ->with(['unit', 'activePermit'])
            ->get()
            ->sortBy(fn (Device $device): string => $device->unit->code.'-'.str_pad((string) $device->number, 2, '0', STR_PAD_LEFT))
            ->groupBy(fn (Device $device): string => $device->unit->name);
    }

    public function disconnectedCount(): int
    {
        return $this->devicesByUnit()->flatten()->filter(fn (Device $device): bool => $device->connectionState() === 'Terputus')->count();
    }

    public function issueDeskTokenAction(): Action
    {
        return Action::make('issueDeskToken')
            ->label('Buat Token Meja')
            ->size('sm')
            ->modalDescription('Token lama untuk meja RT ini langsung hangus. Laptop meja lama terlepas saat token baru dipakai.')
            ->schema([Reauthenticate::field()])
            ->action(function (array $arguments): void {
                $desk = $this->deviceFromArguments($arguments);
                abort_unless($desk?->kind === DeviceKind::Meja, 404);

                try {
                    $this->issuedToken = ['desk' => $desk->name(), 'token' => app(DeviceManager::class)->issueToken($desk, auth()->user())];
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();
                }
            });
    }

    public function releaseDeviceAction(): Action
    {
        return Action::make('releaseDevice')
            ->label('Lepas')
            ->color('danger')
            ->size('sm')
            ->schema([Select::make('reason')->label('Alasan')->options(DeviceReleaseReason::class)->required()])
            ->action(function (array $data, array $arguments): void {
                $device = $this->deviceFromArguments($arguments);
                abort_if($device === null, 404);
                $reason = $data['reason'] instanceof DeviceReleaseReason ? $data['reason'] : DeviceReleaseReason::from($data['reason']);

                app(DeviceManager::class)->release($device, $reason, auth()->user());
                Notification::make()->title($device->code().' dilepas.')->success()->send();
            });
    }

    public function dismissToken(): void
    {
        $this->issuedToken = null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function deviceFromArguments(array $arguments): ?Device
    {
        $election = $this->election();

        return $election === null ? null : Device::query()
            ->where('election_id', $election->id)
            ->where('public_id', $arguments['device'] ?? '')
            ->with('unit')
            ->first();
    }
}
