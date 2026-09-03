<?php

namespace App\Filament\Resources\WorkJournals;

use App\Filament\Resources\WorkJournals\Pages\ManageWorkJournals;
use App\Models\User;
use App\Models\WorkJournal;
use App\Services\ApprovalScopeService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use App\Filament\Exports\WorkJournalExporter;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkJournalResource extends Resource
{
    protected static ?string $model = WorkJournal::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Jurnal Harian';
    protected static ?string $modelLabel = 'Jurnal Harian';
    protected static ?string $pluralModelLabel = 'Jurnal Harian';
    protected static ?int $navigationSort = 1;

    public static function canAccessAllJournals(?User $user = null): bool
    {
        $user ??= auth()->user();

        return (bool) $user && $user->role?->name === 'ADMIN';
    }

    public static function getManagedUserIds(?User $user = null): array
    {
        $user ??= auth()->user();
        if (! $user) {
            return [];
        }

        if (static::canAccessAllJournals($user)) {
            return [];
        }

        $managed = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);
        if (empty($managed)) {
            $managed = User::where('approval_id', $user->id)->pluck('id')->all();
        }

        return $managed;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Forms\Components\Hidden::make('user_id')
                    ->default(fn () => auth()->id())
                    ->dehydrated(),

                TextInput::make('user_nama_lengkap')
                    ->label('Nama Karyawan')
                    ->formatStateUsing(fn ($record) => $record?->user?->nama_lengkap ?? auth()->user()?->nama_lengkap)
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn ($record) => $record !== null)
                    ->columnSpanFull(),

                DatePicker::make('date')
                    ->label('Tanggal')
                    ->default(fn () => now()->toDateString())
                    ->required()
                    ->rules([
                        fn ($record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                            $userId = auth()->id();
                            $query = WorkJournal::where('user_id', $userId)
                                ->whereDate('date', $value)
                                ->whereNull('deleted_at');

                            if ($record) {
                                $query->where('id', '!=', $record->id);
                            }

                            if ($query->exists()) {
                                $fail('Anda sudah mengisi jurnal untuk tanggal ini (' . $value . '). Silakan edit jurnal yang sudah ada.');
                            }
                        },
                    ])
                    ->columnSpanFull(),

                Textarea::make('activity')
                    ->label('Aktivitas')
                    ->placeholder("Tuliskan aktivitas atau pekerjaan Anda...\nContoh:\n- Follow up penagihan 5 customer\n- Rekonsiliasi data mutasi bank\n- Mengikuti rapat mingguan divisi")
                    ->required()
                    ->rows(7)
                    ->autofocus()
                    ->columnSpanFull(),

                Textarea::make('notes')
                    ->label('Catatan (Opsional)')
                    ->placeholder('Catatan atau kendala jika ada...')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('user.nama_lengkap')
                    ->label('Karyawan')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('user.divisi.name')
                    ->label('Divisi')
                    ->badge()
                    ->sortable()
                    ->placeholder('-'),

                TextColumn::make('activity')
                    ->label('Aktivitas')
                    ->limit(80)
                    ->tooltip(fn ($record) => $record->activity)
                    ->wrap(),

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(35)
                    ->placeholder('-')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Waktu Submit')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('date', 'desc')
            ->filters([
                SelectFilter::make('user_id')
                    ->label('Filter Karyawan')
                    ->preload()
                    ->searchable()
                    ->relationship(
                        name: 'user',
                        titleAttribute: 'nama_lengkap',
                        modifyQueryUsing: function (Builder $query): Builder {
                            $user = auth()->user();
                            $query->whereNull('deleted_at')->orderBy('nama_lengkap');

                            if (! static::canAccessAllJournals($user)) {
                                $managed = static::getManagedUserIds($user);
                                $managed[] = $user->id;
                                return $query->whereIn('id', array_unique($managed));
                            }

                            return $query;
                        }
                    )
                    ->visible(fn () => static::canAccessAllJournals(auth()->user()) || !empty(static::getManagedUserIds(auth()->user()))),

                Filter::make('date')
                    ->form([
                        DatePicker::make('date_from')->label('Dari Tanggal'),
                        DatePicker::make('date_until')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['date_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '>=', $date),
                            )
                            ->when(
                                $data['date_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('date', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalWidth('md'),
                EditAction::make()
                    ->modalWidth('md')
                    ->visible(fn ($record) => static::canAccessAllJournals(auth()->user()) || (int) $record->user_id === (int) auth()->id()),
                DeleteAction::make()
                    ->visible(fn ($record) => static::canAccessAllJournals(auth()->user()) || (int) $record->user_id === (int) auth()->id()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->label('Ekspor Pilihan')
                        ->exporter(WorkJournalExporter::class),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageWorkJournals::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user) {
            return $query->whereIn('user_id', []);
        }

        if (static::canAccessAllJournals($user)) {
            return $query;
        }

        // Supervisor can see own + all managed subordinates
        $managedUserIds = static::getManagedUserIds($user);
        $managedUserIds[] = (int) $user->id;

        return $query->whereIn('user_id', array_unique($managedUserIds));
    }
}
