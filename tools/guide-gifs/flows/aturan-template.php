<?php

// Builds a filled attendance import file from the real template class (demo DB only). aturan.js runs it.
// The template is for last month (the app's default periode); only the employees without last-month attendance in the
// demo seed are filled, the other rows stay empty (ignored as "belum diisi" by the import).
// Usage: DB_DATABASE=dnd_demo_aturan CACHE_PREFIX=demo_aturan_ php aturan-template.php <out.xlsx>
// aturan.js also passes rec.SAFE_ENV (env.sh: WhatsApp -> local stub, mail -> log), so the real gateway in .env is never used.

use App\Exports\AttendanceImportTemplateExport;
use App\Models\User;
use App\Support\Periode;
use Illuminate\Contracts\Console\Kernel;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;

if (getenv('DB_DATABASE') !== 'dnd_demo_aturan') {
    fwrite(STDERR, "refusing: DB_DATABASE must be dnd_demo_aturan\n");
    exit(1);
}
$repo = dirname(__DIR__, 3);
chdir($repo);
require $repo.'/vendor/autoload.php';
$app = require $repo.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The template lists every employee: with a cached config DB_DATABASE is ignored and real names would end up in the GIF.
if (config('database.connections.'.config('database.default').'.database') !== 'dnd_demo_aturan') {
    fwrite(STDERR, "refusing: app is not connected to dnd_demo_aturan\n");
    exit(1);
}
if (config('services.whatsapp.api_url') !== getenv('WAG_URL')
    || preg_match('#^http://127\.0\.0\.1:\d+/?$#', (string) config('services.whatsapp.api_url')) !== 1) {
    fwrite(STDERR, "refusing: WAG_URL is not the local WhatsApp stub (pass rec.SAFE_ENV)\n");
    exit(1);
}

$out = $argv[1];
@mkdir(dirname($out), 0777, true);
$periode = Periode::previous();
$raw = Excel::raw(new AttendanceImportTemplateExport(null, $periode), Maatwebsite\Excel\Excel::XLSX);
$tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
file_put_contents($tmp, $raw);

$book = IOFactory::load($tmp);
$sheet = $book->getActiveSheet();
$highestCol = $sheet->getHighestColumn();
$headings = $sheet->rangeToArray('A1:'.$highestCol.'1')[0];
echo "template $periode headings: ".implode(' | ', $headings)."\n";
// 1-based column of the first heading containing $needle; null when the template has no such column.
$col = function (string $needle) use ($headings): ?int {
    foreach ($headings as $index => $heading) {
        if (str_contains((string) $heading, $needle)) {
            return $index + 1;
        }
    }

    return null;
};

// username => [Hari Kerja, late<30, late>30, Sakit (or Sakit/Izin = sakit + izin), Izin (only if the template has it)]
$fill = [
    'demo_manager' => [22, 1, 0, 2, 0],
    'demo_teamleader' => [21, 2, 1, 0, 0],
];
$names = User::query()->whereIn('username', array_keys($fill))->pluck('username', 'nama_lengkap')->all();
$rows = $sheet->getHighestRow();
$filled = 0;
for ($r = 2; $r <= $rows; $r++) {
    $username = $names[(string) $sheet->getCell([2, $r])->getValue()] ?? null;
    if ($username === null) {
        continue;
    }
    [$days, $l30, $m30, $sick, $izin] = $fill[$username];
    $sheet->setCellValue([4, $r], $days);
    $sheet->setCellValue([$col('Late Less'), $r], $l30);
    $sheet->setCellValue([$col('Late More'), $r], $m30);
    $sheet->setCellValue([$col('Sakit'), $r], $sick);
    if ($col('[izin]') !== null) {
        $sheet->setCellValue([$col('[izin]'), $r], $izin);
    }
    echo "row $r: {$sheet->getCell([2, $r])->getValue()} ({$sheet->getCell([3, $r])->getValue()})\n";
    $filled++;
}
IOFactory::createWriter($book, 'Xlsx')->save($out);
echo "filled $filled of ".($rows - 1)." rows -> $out\n";
