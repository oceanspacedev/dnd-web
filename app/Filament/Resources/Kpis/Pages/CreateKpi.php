<?php

namespace App\Filament\Resources\Kpis\Pages;

use Exception;
use App\Filament\Resources\Kpis\KpiResource;
use App\Models\Kpi;
use App\Models\KpiDescription;
use App\Models\KpiDetail;
use App\Models\User;
use App\Services\ApprovalScopeService;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateKpi extends CreateRecord
{
    protected static string $resource = KpiResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // We don't need to mutate data before create since we're overriding the create method
        return $data;
    }

    public function create(bool $another = false): void
    {
        $this->authorizeAccess();

        try {
            $this->callHook('beforeValidate');
            $data = $this->form->getState();
            $this->callHook('afterValidate');

            // Check that percentages add up to 100
            $totalPercentage = $data['percentageMain'] + $data['percentageAdm'] + $data['percentageRep'];
            if ($totalPercentage != 100) {
                Notification::make()
                    ->title('Validation Error')
                    ->body('Total percentage must be 100%')
                    ->danger()
                    ->send();

                $this->halt();
                return;
            }

            $this->callHook('beforeCreate');

            // Start transaction to ensure all data is saved consistently
            DB::beginTransaction();

            $usersQuery = User::query()
                ->whereNull('deleted_at')
                ->where('position_id', $data['position_id']);

            if (Auth::user()?->role?->name !== 'ADMIN') {
                $managedUserIds = ApprovalScopeService::getManagedUserIdsOneLevelDown((int) Auth::id());
                if ($managedUserIds === []) {
                    Notification::make()
                        ->title('Tidak ada user yang valid untuk posisi ini')
                        ->body('Pastikan posisi yang dipilih berisi bawahan langsung atau satu level di bawahnya.')
                        ->warning()
                        ->send();

                    DB::rollBack();
                    $this->halt();
                    return;
                }

                $usersQuery->whereIn('id', array_merge([Auth::id()], $managedUserIds));
            }

            $users = $usersQuery->get();
            if ($users->isEmpty()) {
                Notification::make()
                    ->title('Tidak ada user yang valid untuk posisi ini')
                    ->body('Pastikan posisi yang dipilih berisi bawahan langsung atau satu level di bawahnya.')
                    ->warning()
                    ->send();

                DB::rollBack();
                $this->halt();
                return;
            }

            $date = Carbon::createFromFormat('m/Y', $data['date'])->format('Y-m-d');

            // Create KPIs and KPI details for each user
            foreach ($users as $user) {
                // Create Administration KPI
                if (isset($data['kpi_details_adm']) && count($data['kpi_details_adm']) > 0) {
                    $kpiAdm = Kpi::create([
                        'user_id' => $user->id,
                        'kpi_type_id' => $data['kpi_type_id'],
                        'kpi_category_id' => 1,
                        'date' => $date,
                        'percentage' => $data['percentageAdm'],
                    ]);

                    foreach ($data['kpi_details_adm'] as $details) {
                        KpiDetail::create([
                            'kpi_id' => $kpiAdm->id,
                            'kpi_description_id' => $details['kpi_description_id_adm'],
                            'count_type' => $details['count_type'],
                            'value_plan' => $details['count_type'] === 'RESULT' ? $details['value_plan'] : null,
                            'value_result' => 0,
                            'start' => isset($details['start']) ? Carbon::parse($details['start'])->format('Y-m-d') : null,
                            'end' => isset($details['end']) ? Carbon::parse($details['end'])->format('Y-m-d') : null,
                            'subtasks' => isset($details['subtasks']) ? $details['subtasks'] : null,
                        ]);
                    }
                }

                // Create Reporting KPI
                if (isset($data['kpi_details_rep']) && count($data['kpi_details_rep']) > 0) {
                    $kpiRep = Kpi::create([
                        'user_id' => $user->id,
                        'kpi_type_id' => $data['kpi_type_id'],
                        'kpi_category_id' => 2,
                        'date' => $date,
                        'percentage' => $data['percentageRep'],
                    ]);

                    foreach ($data['kpi_details_rep'] as $details) {
                        KpiDetail::create([
                            'kpi_id' => $kpiRep->id,
                            'kpi_description_id' => $details['kpi_description_id_rep'],
                            'count_type' => $details['count_typeRep'],
                            'value_plan' => $details['count_typeRep'] === 'RESULT' ? $details['value_planRep'] : null,
                            'value_result' => 0,
                            'start' => isset($details['startRep']) ? Carbon::parse($details['startRep'])->format('Y-m-d') : null,
                            'end' => isset($details['endRep']) ? Carbon::parse($details['endRep'])->format('Y-m-d') : null,
                            'subtasks' => isset($details['subtasks']) ? $details['subtasks'] : null,
                        ]);
                    }
                }

                // Create Main Job KPI
                if (isset($data['kpi_details_main']) && count($data['kpi_details_main']) > 0) {
                    $kpiMain = Kpi::create([
                        'user_id' => $user->id,
                        'kpi_type_id' => $data['kpi_type_id'],
                        'kpi_category_id' => 3,
                        'date' => $date,
                        'percentage' => $data['percentageMain'],
                    ]);

                    foreach ($data['kpi_details_main'] as $details) {
                        KpiDetail::create([
                            'kpi_id' => $kpiMain->id,
                            'kpi_description_id' => $details['kpi_description_id_main'],
                            'count_type' => $details['count_typeMain'],
                            'value_plan' => $details['count_typeMain'] === 'RESULT' ? $details['value_planMain'] : null,
                            'value_result' => 0,
                            'start' => isset($details['startMain']) ? Carbon::parse($details['startMain'])->format('Y-m-d') : null,
                            'end' => isset($details['endMain']) ? Carbon::parse($details['endMain'])->format('Y-m-d') : null,
                            'subtasks' => isset($details['subtasks']) ? $details['subtasks'] : null,
                        ]);
                    }
                }
            }

            DB::commit();
            $this->callHook('afterCreate');

            // Success notification
            Notification::make()
                ->title('KPI created')
                ->body('KPIs have been created successfully for all users in the selected position.')
                ->success()
                ->send();

            if ($another) {
                $this->form->fill();
                return;
            }

            $this->redirect($this->getResource()::getUrl('index'));
        } catch (Exception $e) {
            DB::rollBack();

            // Error notification
            Notification::make()
                ->title('Error occurred')
                ->body($e->getMessage())
                ->danger()
                ->send();

            // $this->halt();
        }
    }
}
