<?php

namespace App\Imports;

use Illuminate\Database\Eloquent\Model;
use App\Models\Daily;
use App\Models\DailyLog;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class DailyImportUser implements ToModel, WithHeadingRow
{
    protected $userId, $page;

    function __construct(array $userId, $page)
    {
        $this->userId = $userId;
        $this->page = $page;
    }

    /**
     * @param array $row
     *
     * @return Model|null
     */
    public function model(array $row)
    {
        if (strlen((string) preg_replace('/\s+/', '', $row['date'])) < 6) {
            throw new Exception("Tidak bisa import daily ada format import yang salah pastikan pada bagian tanggal format kolom date menggunakan text dan format tanggal yyyy-mm-dd");
        }

        if (strlen((string) $row['time']) > 6) {
            throw new Exception("Tidak bisa import daily ada format import yang salah pastikan pada bagian jam format kolom time menggunakan text dan format 24 jam contoh 15:00");
        }

        // if ($this->page != 'teams') {
        //     if (auth()->user()->role_id != 1) {
        //         if (auth()->user()->area_id == 2) {
        //             if (Carbon::parse($row['date'])->weekOfYear <= now()->weekOfYear && now() > now()->startOfDay()->startOfWeek()->addDay(1)->addHour(10)) {
        //                 throw new Exception("Tidak bisa import daily pada week ke " . Carbon::parse($row['date'])->weekOfYear . " sudah melibihi hari selasa tanggal " . now()->startOfDay()->startOfWeek()->addDay(1)->addHour(10)->format('d M y') . " jam 10:00");
        //             }
        //         }
        //         else if (Carbon::parse($row['date'])->weekOfYear <= now()->weekOfYear && now() > now()->startOfDay()->startOfWeek()->addHour(17)) {
        //             throw new Exception("Tidak bisa import daily pada week ke ". Carbon::parse($row['date'])->weekOfYear." sudah melibihi hari senin tanggal " . now()->startOfDay()->startOfWeek()->addHour(17)->format('d M y') . " jam 17:00");
        //         }
        //     }
        // }

        if (Carbon::parse($row['date'])->weekOfYear < now()->weekOfYear) {
            throw new Exception("Tidak bisa import daily kurang dari week " . now()->weekOfYear);
        }

        $dailyTasks = [];

        foreach ($this->userId as $userId) {
            $daily = new Daily([
                'user_id' => $userId,
                'date' => Carbon::parse($row['date']),
                'task' => $row['task'],
                'time' => date('H:i', strtotime($row['time'])),
            ]);
            $daily->save();

            //KALAU IMPORT DARI PAGE TEAMS, SIMPAN ADDED BY ID
            if ($this->page == 'teams') {
                $daily->add_id = auth()->user()->id;
                $daily->save();
            }
            $dailyTasks[] = $daily;

            if ($this->page == 'teams') {
                $user = User::find($userId)->nama_lengkap;
                $dailyLog = DailyLog::create([
                    'user_id' => auth()->user()->id,
                    'task_id' => $daily->id,
                    'activity' => 'Mengirim task ' . $daily->task . ' ke ' . $user,
                ]);
                $dailyLog->save();
            }
        }

        return $dailyTasks;
    }

    private function getSelectedUsers($userIds)
    {
        return User::whereIn('id', $userIds)->get();
    }
}
