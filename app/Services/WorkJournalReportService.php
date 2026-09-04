<?php

namespace App\Services;

use App\Ai\Agents\WorkJournalSummaryAgent;
use App\Models\User;
use App\Models\WorkJournal;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WorkJournalReportService
{
    /**
     * Generate AI summary and download report for a user within a date range.
     */
    public function generateAndDownload(User $user, string $dateFrom, string $dateUntil, string $format = 'pdf'): ?StreamedResponse
    {
        $journals = WorkJournal::where('user_id', $user->id)
            ->whereDate('date', '>=', $dateFrom)
            ->whereDate('date', '<=', $dateUntil)
            ->orderBy('date', 'asc')
            ->get();

        if ($journals->isEmpty()) {
            Notification::make()
                ->title('Tidak Ada Data Jurnal')
                ->body('Karyawan '.$user->nama_lengkap.' belum memiliki entri jurnal pada rentang tanggal '.$dateFrom.' s/d '.$dateUntil.'.')
                ->warning()
                ->send();

            return null;
        }

        // 1. Generate Summary using AI Agent (or fallback)
        $aiSummary = $this->generateAiSummary($user, $journals, $dateFrom, $dateUntil);

        // 2. Generate and download document based on format
        $fileName = 'Rekap_Jurnal_'.str($user->nama_lengkap)->slug('_').'_'.$dateFrom.'_sd_'.$dateUntil;

        if ($format === 'docs') {
            return $this->downloadWordDocument($user, $journals, $aiSummary, $dateFrom, $dateUntil, $fileName.'.doc');
        }

        return $this->downloadPdfDocument($user, $journals, $aiSummary, $dateFrom, $dateUntil, $fileName.'.html');
    }

    /**
     * Generate AI summary using Laravel Ai SDK with intelligent fallback.
     */
    protected function generateAiSummary(User $user, $journals, string $dateFrom, string $dateUntil): string
    {
        $defaultProvider = config('ai.default', 'openai');
        $apiKey = config("ai.providers.{$defaultProvider}.key");

        // Prepare context prompt
        $prompt = "Berikut adalah data jurnal harian karyawan:\n";
        $prompt .= "Nama: {$user->nama_lengkap}\n";
        $prompt .= 'Divisi: '.($user->divisi?->name ?? '-')."\n";
        $prompt .= 'Posisi: '.($user->position?->name ?? '-')."\n";
        $prompt .= 'Periode: '.Date::parse($dateFrom)->translatedFormat('d F Y').' s/d '.Date::parse($dateUntil)->translatedFormat('d F Y')."\n\n";
        $prompt .= "Daftar Pekerjaan Harian:\n";

        foreach ($journals as $index => $j) {
            $num = $index + 1;
            $date = Date::parse($j->date)->translatedFormat('l, d F Y');
            $prompt .= "{$num}. Tanggal: {$date}\n";
            $prompt .= "   Aktivitas: {$j->activity}\n";
            if (! empty($j->notes)) {
                $prompt .= "   Catatan/Kendala: {$j->notes}\n";
            }
            $prompt .= "\n";
        }

        // Call Laravel Ai Agent if API key is configured
        if (! empty($apiKey)) {
            try {
                $agent = new WorkJournalSummaryAgent;
                $response = $agent->prompt($prompt);

                return (string) $response;
            } catch (\Throwable $e) {
                // Log and fallback to local analytical engine
                report($e);
            }
        }

        // Local intelligent analytical engine fallback
        return $this->generateLocalSummary($user, $journals, $dateFrom, $dateUntil);
    }

    /**
     * Concise, factual summary generator fallback.
     */
    protected function generateLocalSummary(User $user, $journals, string $dateFrom, string $dateUntil): string
    {
        $activities = [];
        $issues = [];

        foreach ($journals as $j) {
            $acts = explode("\n", $j->activity);
            foreach ($acts as $act) {
                $trimmed = trim($act, " \t\n\r\0\x0B-1234567890.");
                if (! empty($trimmed)) {
                    $activities[] = $trimmed;
                }
            }
            if (! empty($j->notes)) {
                $issues[] = Date::parse($j->date)->translatedFormat('d M').': '.trim($j->notes);
            }
        }

        $uniqueActs = array_unique($activities);
        $topActs = array_slice($uniqueActs, 0, 10);

        $summary = "Pekerjaan utama yang dikerjakan selama periode ini:\n";
        foreach ($topActs as $act) {
            $summary .= '• '.ucfirst($act)."\n";
        }

        if (! empty($issues)) {
            $summary .= "\nCatatan & Kendala:\n";
            foreach (array_slice($issues, 0, 5) as $issue) {
                $summary .= '• '.$issue."\n";
            }
        }

        return trim($summary);
    }

    /**
     * Download as Word Document (.doc) with Microsoft Word compatibility.
     */
    protected function downloadWordDocument(User $user, $journals, string $aiSummary, string $dateFrom, string $dateUntil, string $fileName): StreamedResponse
    {
        $html = $this->buildReportHtml($user, $journals, $aiSummary, $dateFrom, $dateUntil, isWord: true);

        return response()->streamDownload(function () use ($html) {
            echo $html;
        }, $fileName, [
            'Content-Type' => 'application/msword; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Download / View as Printable PDF Document.
     */
    protected function downloadPdfDocument(User $user, $journals, string $aiSummary, string $dateFrom, string $dateUntil, string $fileName): StreamedResponse
    {
        $html = $this->buildReportHtml($user, $journals, $aiSummary, $dateFrom, $dateUntil, isWord: false);

        return response()->streamDownload(function () use ($html) {
            echo $html;
        }, $fileName, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Disposition' => 'inline; filename="'.$fileName.'"',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    /**
     * Build clean minimalist HTML report template showing only Name, Period, Kesimpulan, and Rincian.
     */
    protected function buildReportHtml(User $user, $journals, string $aiSummary, string $dateFrom, string $dateUntil, bool $isWord = false): string
    {
        $formattedDateFrom = Date::parse($dateFrom)->translatedFormat('d F Y');
        $formattedDateUntil = Date::parse($dateUntil)->translatedFormat('d F Y');

        $printScript = $isWord ? '' : '<script>window.onload = function() { window.print(); };</script>';

        $rowsHtml = '';
        foreach ($journals as $index => $j) {
            $num = $index + 1;
            $date = Date::parse($j->date)->translatedFormat('d/m/Y');
            $activity = nl2br(e($j->activity));
            $notes = ! empty($j->notes) ? e($j->notes) : '-';

            $rowsHtml .= "
                <tr>
                    <td style='border: 1px solid #ddd; padding: 8px; text-align: center;'>{$num}</td>
                    <td style='border: 1px solid #ddd; padding: 8px; white-space: nowrap;'>{$date}</td>
                    <td style='border: 1px solid #ddd; padding: 8px; line-height: 1.5;'>{$activity}</td>
                    <td style='border: 1px solid #ddd; padding: 8px;'>{$notes}</td>
                </tr>
            ";
        }

        $summaryParagraphs = nl2br(e($aiSummary));

        return <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Jurnal - {$user->nama_lengkap}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 24px;
            color: #222;
            font-size: 13px;
            line-height: 1.6;
        }
        .meta-box {
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 2px solid #333;
        }
        .meta-item {
            margin-bottom: 6px;
            font-size: 14px;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-top: 20px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .summary-content {
            background: #f9f9f9;
            border: 1px solid #e0e0e0;
            padding: 12px 16px;
            border-radius: 4px;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            font-size: 12px;
        }
        .data-table th {
            background: #f2f2f2;
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        @media print {
            body { padding: 10px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="margin-bottom: 16px; text-align: right;">
        <button onclick="window.print()" style="padding: 6px 14px; background: #2563eb; color: #fff; border: none; border-radius: 4px; cursor: pointer;">
            🖨️ Cetak / Simpan PDF
        </button>
    </div>

    <!-- IDENTITAS -->
    <div class="meta-box">
        <div class="meta-item"><strong>Nama Karyawan:</strong> {$user->nama_lengkap}</div>
        <div class="meta-item"><strong>Periode:</strong> {$formattedDateFrom} s/d {$formattedDateUntil}</div>
    </div>

    <!-- KESIMPULAN -->
    <div class="section-title">Kesimpulan</div>
    <div class="summary-content">
        {$summaryParagraphs}
    </div>

    <!-- RINCIAN -->
    <div class="section-title">Rincian</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 5%; text-align: center;">No</th>
                <th style="width: 18%;">Tanggal</th>
                <th style="width: 52%;">Aktivitas</th>
                <th style="width: 25%;">Catatan</th>
            </tr>
        </thead>
        <tbody>
            {$rowsHtml}
        </tbody>
    </table>

    {$printScript}
</body>
</html>
HTML;
    }
}
