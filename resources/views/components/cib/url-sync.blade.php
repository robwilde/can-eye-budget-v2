@props(['params'])

{{-- Livewire does not write #[Url] changes back for a lazy-loaded component, so the widget mirrors them itself. --}}
<template x-init="Object.entries(@js($params)).forEach(([property, key]) => $wire.$watch(property, value => {
    const url = new URL(window.location.href);
    value ? url.searchParams.set(key, value) : url.searchParams.delete(key);
    history.replaceState(history.state, '', url);
}))"></template>
