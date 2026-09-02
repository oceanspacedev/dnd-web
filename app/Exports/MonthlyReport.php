<?php

namespace App\Exports;

use App\Models\Monthly;
use App\Models\User;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MonthlyReport implements FromArray, WithMapping, WithHeadings
{
    protected string $month;

    function __construct(string $month)
    {
        $this->month = $month;
    }

    public function array(): array
    {
        $data = array();
        $users = User::with('area', 'divisi')
            ->where('mn', 1)
            ->orWhere('mr', 1)
            ->get()
            ->sortBy('nama_lengkap');
        foreach ($users as $user) {
            $kpiMonthly = 0;
            $valueMonthly = 0;
            $totalClosedMonthly = 0;
            $totalTaskmonthly = 0;
            $tasks = Monthly::with('user', 'user.area', 'user.divisi')
                ->where('date', $this->month)
                ->where('user_id', $user->id)
                ->get();
            $totalTaskmonthly = count($tasks);
            foreach ($tasks as $task) {
                if ($task->value > 0) {
                    $valueMonthly += $task->value;
                    $totalClosedMonthly++;
                }
            }
            if ($totalTaskmonthly && $totalClosedMonthly) {
                $kpiMonthly = $valueMonthly / $totalTaskmonthly > 1 ? 20 : ($valueMonthly / $totalTaskmonthly) * 20;
            }
            if($totalClosedMonthly < 10){
                $totalClosedMonthly = '0'.$totalClosedMonthly;
            }

            if ($totalTaskmonthly < 10) {
                $totalTaskmonthly = '0' . $totalTaskmonthly;
            }

            array_push($data, [
                'user' => $user,
                'actm' =>  $totalClosedMonthly . "/" . $totalTaskmonthly,
                'kpiMonthly' => $kpiMonthly,
            ]);
        }

        return $data;
    }

    public function headings(): array
    {
        return [
            'Nama',
            'Dept',
            'Divisi',
            'Act/Tar',
            'Point',
        ];
    }

    public function map($row): array
    {
        return [
            $row['user']->nama_lengkap,
            $row['user']->area->name,
            $row['user']->divisi->name,
            $row['actm'],
            number_format($row['kpiMonthly'], 1, ',', ' '),
        ];
    }
}
