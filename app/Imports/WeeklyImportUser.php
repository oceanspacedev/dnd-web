<?php

namespace App\Imports;

use App\Helpers\ConvertDate;
use App\Models\User;
use App\Models\Weekly;
use App\Models\WeeklyLog;
use Exception;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class WeeklyImportUser implements ToModel, WithHeadingRow
{
    protected $userId, $page;

    function __construct(array $userId, $page)
    {
        $this->userId = $userId;
        $this->page = $page;
    }

    /**
     * @param Collection $collection
     */
    public function model(array $row)
    {
        $year = preg_replace('/\s+/', '', $row['year']);
        $week = preg_replace('/\s+/', '', $row['week']);
        $tipe = preg_replace('/\s+/', '', strtoupper($row['tipe']));

        $userAreaIds = [];
        foreach ($this->userId as $userId) {
            $user = User::find($userId);
            if ($user) {
                $userAreaIds[] = $user->area_id;
            }
        }

        foreach ($userAreaIds as $userAreaId) {
            if (auth()->user()->role_id == 2) {
                $monday = ConvertDate::getMondayOrSaturday($year, $week, true);
                if ($userAreaId == 2 && now() > $monday->addDay(1)->addHour(10)) {
                    throw new Exception('Tidak bisa import weekly week ' . now()->week . ' sudah lebih dari '.$monday->format('d M y'));
                }
                if ($userAreaId != 2 && now() > $monday->addHour(17)) {
                    throw new Exception('Tidak bisa import weekly week ' . now()->week . ' sudah lebih dari ' . $monday->format('d M y - H:i'));
                }
            }
        }

        if ($year <= now()->year && $week < now()->weekOfYear) {
            throw new Exception("Tidak bisa import weekly kurang dari week " . now()->weekOfYear);
        }
        if ($week > 52 || $year < 2022 || $week < 0) {
            throw new Exception("Tidak bisa import weekly lebih dari week 52 atau minimal tahun 2022");
        }

        $weeklyTasks = [];

        foreach ($this->userId as $userId) {
            $user = User::find($userId);
            if ($tipe == "NON") {
                $weekly = new Weekly([
                    'user_id' => $user->id,
                    'task' => $row['task'],
                    'year' => $year,
                    'week' => $week,
                    'tipe' => $tipe,
                    'status_non' => 0,
                ]);
                $weekly->save();

                //KALAU IMPORT DARI PAGE TEAMS, SIMPAN ADDED BY ID
                if ($this->page == 'teams') {
                    $weekly->add_id = auth()->user()->id;
                    $weekly->save();
                }
                $weeklyTasks[] = $weekly;

            } else if ($tipe == 'RESULT') {
                if (!$row['value_plan_result']) {
                    throw new Exception('Untuk task bertipe result wajib isi kolom "value_plan_result"');
                }
                if (!ctype_digit(preg_replace('/\s+/', '', $row['value_plan_result']))) {
                    throw new Exception('Tidak bisa import weekly untuk kolom "value_plan_result" harus berisi nominal angka');
                }

                $wr = $user->wr;
                if ($wr) {
                    $weekly = new Weekly([
                        'user_id' => $user->id,
                        'task' => $row['task'],
                        'year' => $year,
                        'week' => $week,
                        'tipe' => $tipe,
                        'value_plan' => preg_replace('/\s+/', '', $row['value_plan_result']),
                        'value_actual' => 0,
                        'status_result' => 0,
                    ]);
                    $weekly->save();
                    
                    //KALAU IMPORT DARI PAGE TEAMS, SIMPAN ADDED BY ID
                    if ($this->page == 'teams') {
                        $weekly->add_id = auth()->user()->id;
                        $weekly->save();
                    }
                    $weeklyTasks[] = $weekly;
                } else {
                    throw new Exception('Tidak bisa import weekly anda tidak memiliki task weekly result');
                }

            } else {
                throw new Exception('ada kesalahan format kolom tipe harus berisi NON atau RESULT');
            }
                if ($this->page == 'teams') {
                $weeklyLog = WeeklyLog::create([
                    'user_id' => auth()->user()->id,
                    'task_id' => $weekly->id,
                    'activity' => 'Mengirim task ' . Weekly::find($weekly->id)->task . ' ke ' . $user->nama_lengkap,
                ]);
                $weeklyLog->save();
            } 
        }
        return $weeklyTasks;
    }
}
