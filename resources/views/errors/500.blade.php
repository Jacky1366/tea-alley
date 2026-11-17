@php
    $en = [__("Something went wrong", [], 'en'), __("Something went wrong on our side. Please try again in a moment.", [], 'en')];
    $zh = [__("Something went wrong", [], 'zh_TW'), __("Something went wrong on our side. Please try again in a moment.", [], 'zh_TW')];
@endphp
<x-error-page code="500" :en="$en" :zh="$zh" />
