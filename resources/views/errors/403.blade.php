@php
    $en = [__("Access denied", [], 'en'), __("You don't have access to this page.", [], 'en')];
    $zh = [__("Access denied", [], 'zh_TW'), __("You don't have access to this page.", [], 'zh_TW')];
@endphp
<x-error-page code="403" :en="$en" :zh="$zh" />
