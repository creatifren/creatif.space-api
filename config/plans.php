<?php

return [
    /*
     * Days ahead a paid plan (Pro, Max, Team, Enterprise) may schedule a
     * post, for plans whose `schedule_window_days` quota is null. Free keeps
     * its own quota (7). Past the relay's horizon (Outstand: 30 days) the
     * post is held here and sent once its slot is close enough
     * (SendApprovedHeldPosts). Rizal, 18 Nov 2026.
     */
    'paid_schedule_window_days' => (int) env('PAID_SCHEDULE_WINDOW_DAYS', 90),
];
