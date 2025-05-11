@props([
    'preview' => false,
])

<div
    @class([
        'doc-template-container flex justify-center',
        'scale-[0.85] origin-top' => $preview === true,
    ])
>
    <div class="overflow-auto">
        <div
            @class([
                'doc-template-paper bg-[#ffffff] shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10',
                'w-[51.25rem] h-[64rem]' => $preview === false,
                'w-[48rem] h-[61.75rem]' => $preview === true,
            ])
            @style([
                'scrollbar-width: thin;' => $preview === false,
            ])
        >
            {{ $slot }}
        </div>
    </div>
</div>
