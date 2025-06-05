<div class="text-xs text-right">
    <h2 class="text-base font-semibold">{{ $companyDTO->name }}</h2>
    @if($formattedAddress = $companyDTO->getFormattedAddressHtml())
        {!! $formattedAddress !!}
    @endif
</div>
