<?php

namespace App\Filament\Resources\Units\Pages;

use App\Filament\Resources\Units\UnitResource;
use App\Models\Unit;
use App\Services\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageUnits extends ManageRecords
{
    protected static string $resource = UnitResource::class;

    public function getSubheading(): ?string
    {
        return 'RT dipakai untuk data pemilih, calon Ketua RT, akun Admin RT, dan laptop TPS. Seret baris untuk mengubah urutan.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addUpTo')
                ->label('Tambah RT sampai nomor …')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('gray')
                ->visible(fn (): bool => UnitResource::canCreate())
                ->modalHeading('Tambah beberapa RT sekaligus')
                ->modalDescription(fn (): string => 'Sekarang ada '.Unit::query()->count().' RT. RT yang belum ada sampai nomor ini akan dibuat (nama "RT 10", "RT 11", dst.).')
                ->schema([
                    TextInput::make('last')
                        ->label('Sampai RT nomor')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(99)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $created = [];

                    foreach (range(1, (int) $data['last']) as $number) {
                        $code = str_pad((string) $number, 2, '0', STR_PAD_LEFT);

                        if (Unit::query()->where('code', $code)->orWhere('code', (string) $number)->exists()) {
                            continue;
                        }

                        $unit = Unit::query()->create(['code' => $code, 'name' => "RT {$code}", 'sort' => $number]);
                        app(AuditLogger::class)->log('unit.created', $unit, meta: $unit->only(['code', 'name', 'sort']));
                        $created[] = $unit->name;
                    }

                    Notification::make()
                        ->title($created === [] ? 'Tidak ada RT baru; semuanya sudah ada.' : count($created).' RT ditambahkan: '.implode(', ', $created).'.')
                        ->success()
                        ->send();
                }),

            CreateAction::make()
                ->label('Tambah RT')
                ->createAnother(false)
                ->after(fn (Unit $record) => app(AuditLogger::class)->log('unit.created', $record, meta: $record->only(['code', 'name', 'sort']))),
        ];
    }
}
