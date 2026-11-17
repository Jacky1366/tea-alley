{{-- Retry-After comes from the rate limiter (ThrottleRequests); never the exception's message. --}}
@php
    $headers = isset($exception) && method_exists($exception, 'getHeaders') ? $exception->getHeaders() : [];
    $seconds = ['seconds' => max(1, intval($headers['Retry-After'] ?? 60))];
    $en = [__("Too many requests", [], 'en'), __("Too many attempts. Please wait :seconds seconds and try again.", $seconds, 'en')];
    $zh = [__("Too many requests", [], 'zh_TW'), __("Too many attempts. Please wait :seconds seconds and try again.", $seconds, 'zh_TW')];
@endphp
<x-error-page code="429" :en="$en" :zh="$zh" />
