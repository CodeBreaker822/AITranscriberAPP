@php
    $footerText = config('app.footer_license')
        ? 'ASTRA — Adaptive Speech Transcription and Recording Assistant. All rights reserved.'
        : (string) config('app.footer_text');
@endphp

<footer data-app-footer class="shrink-0 rounded-lg border border-white/10 bg-slate-950/70 px-4 py-2 text-xs text-slate-400">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <p>&copy; {{ date('Y') }} {{ $footerText }}</p>
    </div>
</footer>
