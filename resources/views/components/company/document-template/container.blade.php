@props([
    'preview' => false,
])

<div class="doc-template-container flex justify-center p-6">
    <div
        @class([
            'doc-template-paper bg-[#ffffff] shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10',
            'w-full max-w-[820px] min-h-[1066px] max-h-[1200px] overflow-y-auto' => $preview === false,
            'w-[38.25rem] h-[49.5rem] overflow-hidden' => $preview === true,
        ])
        @style([
            'scrollbar-width: thin;' => $preview === false,
        ])
    >
        {{ $slot }}
    </div>
</div>
