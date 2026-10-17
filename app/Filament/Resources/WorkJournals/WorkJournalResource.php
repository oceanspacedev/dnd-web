<?php

namespace App\Filament\Resources\WorkJournals;

use App\Filament\Exports\WorkJournalExporter;
use App\Filament\Resources\WorkJournals\Pages\ManageWorkJournals;
use App\Models\User;
use App\Models\WorkJournal;
use App\Policies\WorkJournalPolicy;
use App\Services\ApprovalScopeService;
use App\Services\DataAccessService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkJournalResource extends Resource
{
    protected static ?string $model = WorkJournal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Jurnal Harian';

    protected static ?string $modelLabel = 'Jurnal Harian';

    protected static ?string $pluralModelLabel = 'Jurnal Harian';

    protected static ?int $navigationSort = 1;

    /**
     * Super admin: ADMIN dengan akses seluruh badan usaha.
     */
    public static function canAccessAllJournals(?User $user = null): bool
    {
        $user ??= auth()->user();

        return DataAccessService::hasGlobalAccess($user);
    }

    /**
     * Bawahan approval (langsung + satu level). Kosong untuk super admin (melihat semua).
     *
     * @return array<int>
     */
    public static function getManagedUserIds(?User $user = null): array
    {
        $user ??= auth()->user();
        if (! $user) {
            return [];
        }

        if (static::canAccessAllJournals($user)) {
            return [];
        }

        return ApprovalScopeService::getManagedUserIdsOneLevelDown((int) $user->id);
    }

    public static function form(Schema $schema): Schema
    {
        // Pemilik jurnal tidak diambil dari form (state Livewire bisa dimanipulasi):
        // jurnal baru selalu milik user login (ManageWorkJournals), edit tidak mengubah pemilik.
        return $schema
            ->components([
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
                            // Unik per pemilik jurnal (admin bisa mengedit jurnal karyawan lain).
                            $userId = $record?->user_id ?? auth()->id();
                            $query = WorkJournal::where('user_id', $userId)
                                ->whereDate('date', $value)
                                ->whereNull('deleted_at');

                            if ($record) {
                                $query->where('id', '!=', $record->id);
                            }

                            if ($query->exists()) {
                                $fail('Anda sudah mengisi jurnal untuk tanggal ini ('.$value.'). Silakan edit jurnal yang sudah ada.');
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
                        // Opsi karyawan mengikuti cakupan daftar jurnal.
                        modifyQueryUsing: fn (Builder $query): Builder => WorkJournalPolicy::scopeVisible(
                            $query->whereNull('deleted_at')->orderBy('nama_lengkap'),
                            auth()->user(),
                            $query->qualifyColumn('id'),
                        ),
                    )
                    ->visible(fn () => static::canAccessAllJournals(auth()->user())
                        || DataAccessService::isAreaScopedAdmin(auth()->user())
                        || ! empty(static::getManagedUserIds(auth()->user()))),

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
                    ->visible(fn ($record) => (bool) auth()->user()?->can('update', $record)),
                DeleteAction::make()
                    ->visible(fn ($record) => (bool) auth()->user()?->can('delete', $record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->label('Ekspor Pilihan')
                        ->exporter(WorkJournalExporter::class),
                    // Setiap jurnal terpilih dicek dengan WorkJournalPolicy::delete (atasan tidak boleh
                    // menghapus jurnal bawahan lewat aksi massal).
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords(),
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
        // Super admin: semua; admin per badan usaha: badan usaha yang diizinkan + tim + diri sendiri;
        // lainnya: diri sendiri + bawahan approval.
        $user = auth()->user();
        $query = WorkJournalPolicy::scopeVisible(parent::getEloquentQuery(), $user);

        // Hak lihat/edit/hapus admin per badan usaha dicek per baris dari badan usaha & role pemilik:
        // muat sekaligus agar tidak ada query per baris.
        if (DataAccessService::isAreaScopedAdmin($user)) {
            $query->with('user.role');
        }

        return $query;
    }
}
