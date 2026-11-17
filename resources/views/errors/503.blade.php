@php
    $en = [__("Be right back", [], 'en'), __("We're down for a short break. Please check back soon.", [], 'en')];
    $zh = [__("Be right back", [], 'zh_TW'), __("We're down for a short break. Please check back soon.", [], 'zh_TW')];
@endphp
<x-error-page code="503" :en="$en" :zh="$zh" />
