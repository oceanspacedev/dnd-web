<?php

namespace App\Imports;

use App\Helpers\ConvertDate;
use App\Models\Monthly;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class MonthlyImportUser implements ToModel, WithHeadingRow
{
    protected $user;

    function __construct($user)
    {
        $this->user = $user;
    }

    /**
     * @param Collection $collection
     */
    public function model(array $row)
    {
        if (strlen((string) $row['date']) < 6) {
            throw new Exception("Tidak bisa import monthly ada format import yang salah pastikan pada bagian tanggal format kolom date menggunakan text dan format tanggal yyyy-mm-dd");
        }
        $tipe = preg_replace('/\s+/', '', strtoupper($row['tipe']));
        $day = Carbon::parse($row['date']);

        if (now() > $day->startOfDay()->startOfMonth()->addDay(5)) {
            throw new Exception('Tidak bisa import monthly kurang dari bulan ini atau lebih dari H+5 di bulan yang berjalan');
        }

        if ($tipe == "NON") {
            return new Monthly([
                'user_id' => $this->user->id,
                'task' => $row['task'],
                'date' => Carbon::parse($row['date'])->startOfMonth(),
                'tipe' => $tipe,
                'status_non' => 0,
            ]);
        } else if ($tipe == 'RESULT') {
            if (!$row['value_plan_result']) {
                throw new Exception('Untuk task bertipe result wajib isi kolom "value_plan_result"');
            }
            if (!ctype_digit(preg_replace('/\s+/', '', $row['value_plan_result']))) {
                throw new Exception('Tidak bisa import monthly untuk kolom "value_plan_result" harus berisi nominal angka');
            }
            if ($this->user->mr) {
                return new Monthly([
                    'user_id' => $this->user->id,
                    'task' => $row['task'],
                    'date' => Carbon::parse($row['date'])->startOfMonth(),
                    'tipe' => $tipe,
                    'value_plan' => preg_replace('/\s+/', '', $row['value_plan_result']),
                    'value_actual' => 0,
                    'status_result' => 0,
                ]);
            } else {
                throw new Exception('Tidak bisa import monthly anda tidak memiliki task monthly result');
            }
        } else {
            throw new Exception('ada kesalahan format kolom tipe harus berisi NON atau RESULT');
        }
    }
}
