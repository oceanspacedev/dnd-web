<?php

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

class WorkJournalSummaryAgent implements Agent
{
    use Promptable;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): string
    {
        return <<<'INSTRUCTIONS'
Tugas Anda adalah merangkum secara langsung dan padat pekerjaan apa saja yang dikerjakan oleh karyawan berdasarkan data jurnal yang diberikan.
Jelaskan intisari pekerjaan nyata yang telah diselesaikan serta kendala jika ada.
Gunakan gaya bahasa yang ringkas, jelas, dan faktual. Jangan gunakan basa-basi, jangan gunakan pengantar pembuka/penutup, dan jangan gunakan analisis HR yang berbelit-belit.
INSTRUCTIONS;
    }
}
