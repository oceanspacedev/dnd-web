<?php

namespace App\Filament\Resources\ScoringRules\Pages;

use App\Filament\Resources\ScoringRules\ScoringRuleResource;
use App\Models\ScoringRule;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;

class CreateScoringRule extends CreateRecord
{
    protected static string $resource = ScoringRuleResource::class;

    protected static bool $canCreateAnother = false;

    /**
     * ID versi sumber dari aksi "Duplikat jadi versi baru". Bertipe mixed karena query string bisa berisi apa
     * saja (mis. ?duplicate[]=1); isian yang bukan ID angka diabaikan di fillForm().
     */
    #[Url]
    public mixed $duplicate = null;

    protected function fillForm(): void
    {
        $duplicateOf = (is_string($this->duplicate) || is_int($this->duplicate)) && ctype_digit((string) $this->duplicate)
            ? ScoringRule::query()->find((int) $this->duplicate)
            : null;

        $this->callHook('beforeFill');

        $this->form->fill(ScoringRuleResource::newVersionFormData($duplicateOf));

        $this->callHook('afterFill');

        $leftOut = ScoringRuleResource::inactiveComponentsLeftOut(ScoringRuleResource::newVersionSource($duplicateOf));

        if ($leftOut !== []) {
            Notification::make()
                ->title('Komponen nonaktif tidak disertakan')
                ->body('Versi sumber memakai komponen yang sudah dinonaktifkan: '.implode('; ', $leftOut)
                    .'. Komponen itu tidak dinilai di versi baru. Aktifkan lagi di menu Komponen Kehadiran bila masih perlu dinilai.')
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [
            ...ScoringRuleResource::mutateFormDataForSave($data),
            'created_by' => auth()->id(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return ScoringRuleResource::saveWithFieldErrors(fn (): Model => parent::handleRecordCreation($data));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
