<?php

// 顯示格式。日期為 Carbon translatedFormat() 格式（見 App\Support\LocalTime），
// 中文的「上午/下午」要放在時間前面。分隔符號給 App\Support\OptionSummary 使用。
return [
    'time' => 'A g:i',                  // 下午 2:15
    'day_time' => 'D A g:i',            // 週五 上午 11:00
    'date_time' => 'Y年n月j日 A g:i',    // 2026年11月18日 下午 2:15
    'day_date_time' => 'n月j日（D）A g:i', // 11月18日（週三）下午 3:30

    'option_group' => ':group：:options',
    'option_separator' => '、',
    'group_separator' => ' · ',
];
