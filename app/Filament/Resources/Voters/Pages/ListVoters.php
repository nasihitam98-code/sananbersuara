<?php

namespace App\Filament\Resources\Voters\Pages;

use App\Filament\Resources\Voters\VoterResource;
use App\Models\Unit;
use App\Models\User;
use App\Services\Voters\VoterImport;
use App\Services\Voting\VotingException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class ListVoters extends ListRecords
{
    protected static string $resource = VoterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->bulkAddAction(),
            CreateAction::make()->label('Tambah pemilih'),
        ];
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
            ->visible(fn (): bool => VoterResource::canCreate())
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
