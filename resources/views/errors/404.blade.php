@php
    $en = [__("Page not found", [], 'en'), __("We couldn't find that page.", [], 'en')];
    $zh = [__("Page not found", [], 'zh_TW'), __("We couldn't find that page.", [], 'zh_TW')];
@endphp
<x-error-page code="404" :en="$en" :zh="$zh" />
