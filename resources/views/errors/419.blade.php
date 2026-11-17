{{-- The session expired (a page left open too long) or the form's security token is stale. --}}
@php
    $en = [__("Page expired", [], 'en'), __("This page has expired. Please refresh the page and try again.", [], 'en')];
    $zh = [__("Page expired", [], 'zh_TW'), __("This page has expired. Please refresh the page and try again.", [], 'zh_TW')];
@endphp
<x-error-page code="419" :en="$en" :zh="$zh" />
