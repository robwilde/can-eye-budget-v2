<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => __('Report'), 'appearance' => \App\Enums\Theme::Light])
</head>
<body class="min-h-screen bg-white text-black">
    <livewire:category-report :print="true" />
</body>
</html>
