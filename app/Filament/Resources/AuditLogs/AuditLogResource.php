<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use App\Models\Election;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Audit log baca-saja untuk Super Admin.
 */
class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Sistem';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'catatan audit';

    protected static ?string $pluralModelLabel = 'Audit Log';

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('occurred_at')->label('Waktu')->dateTime('d M Y H:i:s'),
                TextEntry::make('actor_label')->label('Pelaku')->placeholder(fn (AuditLog $record): string => $record->actor_type),
                TextEntry::make('action')->label('Aksi'),
                TextEntry::make('subject_type')->label('Objek')->placeholder('-'),
                TextEntry::make('subject_id')->label('ID objek')->placeholder('-'),
                TextEntry::make('reason_code')->label('Alasan')->placeholder('-'),
                TextEntry::make('note')->label('Catatan')->placeholder('-')->columnSpanFull(),
                TextEntry::make('meta')->label('Detail')->placeholder('-')->columnSpanFull()
                    ->formatStateUsing(fn ($state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
                TextEntry::make('ip_address')->label('IP')->placeholder('-'),
                TextEntry::make('hash')->label('Hash')->fontFamily('mono'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->label('Waktu')->dateTime('d M H:i:s')->sortable(),
                TextColumn::make('actor_label')->label('Pelaku')->placeholder(fn (AuditLog $record): string => $record->actor_type)->searchable(),
                TextColumn::make('action')->label('Aksi')->badge()->searchable(),
                TextColumn::make('subject_type')->label('Objek')->placeholder('-'),
                TextColumn::make('reason_code')->label('Alasan')->placeholder('-'),
                TextColumn::make('note')->label('Catatan')->limit(40)->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('election_id')
                    ->label('Pemilihan')
                    ->options(fn (): array => Election::query()->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAuditLogs::route('/'),
        ];
    }
}
