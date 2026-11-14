<?php

// Display formats. The date patterns are Carbon translatedFormat() patterns; see
// App\Support\LocalTime. The separators are used by App\Support\OptionSummary.
return [
    'time' => 'g:i A',                  // 2:15 PM
    'day_time' => 'D g:i A',            // Fri 11:00 AM
    'date_time' => 'M j, Y g:i A',      // Nov 18, 2026 2:15 PM
    'day_date_time' => 'D, M j g:i A',  // Wed, Nov 18 3:30 PM

    'option_group' => ':group: :options',
    'option_separator' => ', ',
    'group_separator' => ' · ',
];
