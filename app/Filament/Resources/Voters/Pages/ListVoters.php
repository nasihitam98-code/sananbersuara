<?php

namespace App\Filament\Resources\Voters\Pages;

use App\Filament\Resources\Voters\VoterResource;
use App\Models\Unit;
use App\Models\User;
use App\Models\Voter;
use App\Services\Voters\VoterImport;
use App\Services\Voting\VotingException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListVoters extends ListRecords
{
    protected static string $resource = VoterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->bulkAddAction(),
            // Tambah ke RT yang sedang dibuka (tab), agar tidak tercampur.
            CreateAction::make()
                ->label(fn (): string => (VoterResource::votingIsLive() ? 'Tambah pemilih darurat' : 'Tambah pemilih').(($unit = $this->activeUnit()) ? " {$unit->name}" : ''))
                ->url(fn (): string => VoterResource::getUrl('create', array_filter(['rt' => $this->activeUnit()?->code]))),
        ];
    }

    /**
     * Admin RT hanya melihat RT sendiri: judulnya menyebut RT itu.
     */
    public function getTitle(): string
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdminRt() && $user->unit !== null ? "Data Pemilih {$user->unit->name}" : parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        return VoterResource::votingIsLive()
            ? 'Pemilihan sedang berlangsung: daftar dibekukan. Di sini hanya lihat, cari, ubah nama, dan tambah pemilih darurat. Untuk menghapus nama yang tidak berhak, pakai tombol "Hapus dari daftar" di Meja Izin.'
            : null;
    }

    /**
     * Super Admin: satu tab per RT (dengan jumlah pemilih aktif) agar data tiap RT tidak tercampur; pilih RT dulu,
     * lalu tambah/tempel nama untuk RT itu. Admin RT hanya melihat RT sendiri, jadi tanpa tab.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->isSuperAdmin()) {
            return [];
        }

        $counts = Voter::query()->where('is_active', true)->selectRaw('unit_id, count(*) as total')->groupBy('unit_id')->pluck('total', 'unit_id');

        return Unit::query()->orderBy('sort')->get()
            ->mapWithKeys(fn (Unit $unit): array => [$unit->code => Tab::make($unit->name)
                ->badge((int) ($counts[$unit->id] ?? 0))
                // Saat mencari nama, cari di semua RT (kolom RT menunjukkan RT-nya): nama di RT lain tetap ketemu.
                ->modifyQueryUsing(fn (Builder $query): Builder => filled(trim((string) $this->tableSearch)) ? $query : $query->where('unit_id', $unit->id))])
            ->put('semua', Tab::make('Semua RT')->badge((int) $counts->sum()))
            ->all();
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return array_key_first($this->getCachedTabs());
    }

    /** RT dari tab yang sedang dibuka (null untuk "Semua RT" atau Admin RT). */
    private function activeUnit(): ?Unit
    {
        return filled($this->activeTab) && $this->activeTab !== 'semua'
            ? Unit::query()->where('code', $this->activeTab)->first()
            : null;
    }

    /**
     * Tempel daftar nama (dari Excel/WhatsApp/kertas yang diketik): cukup nama dan RT. Pemeriksaan sama dengan import
     * file; satu baris bermasalah = tidak ada yang disimpan, agar tidak ada yang setengah masuk.
     */
    private function bulkAddAction(): Action
    {
        /** @var User $user */
        $user = auth()->user();

        return Action::make('bulkAdd')
            ->label('Tambah banyak pemilih')
            ->icon(Heroicon::OutlinedQueueList)
            ->color('gray')
            ->visible(fn (): bool => VoterResource::canCreate() && ! VoterResource::votingIsLive())
            ->modalHeading('Tambah banyak pemilih sekaligus')
            ->modalDescription($user->isSuperAdmin()
                ? 'Satu orang per baris: "Nama | RT 03", atau tempel 2 kolom (nama, RT) dari Excel. Baris tanpa RT memakai RT yang dipilih.'
                : "Satu nama per baris. Semua masuk {$user->unit?->name}.")
            ->modalSubmitActionLabel('Tambahkan')
            ->schema([
                Select::make('unit_id')
                    ->label('RT untuk baris tanpa RT (opsional)')
                    ->options(fn (): array => Unit::query()->orderBy('sort')->pluck('name', 'id')->all())
                    ->placeholder('Kosong (RT ditulis per baris)')
                    // Bawaan: RT dari tab yang sedang dibuka, jadi cukup tempel nama.
                    ->default(fn (): ?int => $this->activeUnit()?->id)
                    ->visible($user->isSuperAdmin()),
                Textarea::make('names')
                    ->label('Daftar pemilih')
                    ->placeholder($user->isSuperAdmin() ? "Bapak Sutrisno | RT 01\nIbu Sumiati | RT 01\nAhmad Fauzi | 2" : "Bapak Sutrisno\nIbu Sumiati\nAhmad Fauzi")
                    ->helperText('Alamat boleh ditambah sebagai kolom ketiga: "Nama | RT 03 | Jl. Mawar 12". Data lain bisa dilengkapi nanti lewat Ubah.')
                    ->rows(12)
                    ->required(),
                Checkbox::make('confirm_same_names')
                    ->label('Tetap tambahkan nama yang sama dengan yang sudah terdaftar (orang berbeda)'),
            ])
            ->action(function (array $data, Action $action) use ($user): void {
                $defaultCode = $user->isSuperAdmin()
                    ? Unit::query()->find($data['unit_id'] ?? null)?->code
                    : $user->unit?->code;

                try {
                    $rows = app(VoterImport::class)->previewText((string) $data['names'], $defaultCode, $user);
                    $errors = collect($rows)->filter(fn (array $row): bool => $row['errors'] !== []);
                    $warnings = collect($rows)->filter(fn (array $row): bool => $row['errors'] === [] && $row['warnings'] !== []);

                    if ($errors->isNotEmpty()) {
                        $this->notifyRows('Tidak ada yang disimpan. Perbaiki dulu:', $errors, 'errors', 'danger');
                        $action->halt();
                    }

                    if ($warnings->isNotEmpty() && ! ($data['confirm_same_names'] ?? false)) {
                        $this->notifyRows('Nama yang sama sudah terdaftar. Bila memang orang berbeda, centang kotak di bawah lalu Tambahkan lagi:', $warnings, 'warnings', 'warning');
                        $action->halt();
                    }

                    $result = app(VoterImport::class)->import($rows, $user, includeWarnings: (bool) ($data['confirm_same_names'] ?? false));
                } catch (VotingException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->persistent()->send();
                    $action->halt();

                    return;
                }

                Notification::make()->title("{$result['imported']} pemilih ditambahkan.")->success()->send();
            });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function notifyRows(string $title, $rows, string $key, string $status): void
    {
        $lines = $rows->take(8)->map(fn (array $row): string => "Baris {$row['line']} ({$row['name']}): ".implode('; ', $row[$key]));

        Notification::make()
            ->title($title)
            ->body($lines->implode("\n").($rows->count() > 8 ? "\n… dan ".($rows->count() - 8).' baris lain.' : ''))
            ->status($status)
            ->persistent()
            ->send();
    }
}
