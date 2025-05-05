@php
    $data = $this->form->getRawState();
    $document = \App\DTO\DocumentPreviewDTO::fromSettings($this->record, $data);
@endphp

{!! $document->getFontHtml() !!}

<style>
    .doc-template-paper {
        font-family: '{{ $document->font->getLabel() }}', sans-serif;
    }
</style>

<x-company.document-template.container class="default-template-container" preview>

    <x-company.document-template.header class="default-template-header border-b">
        <div class="w-1/3">
            @if($document->logo && $document->showLogo)
                <x-company.document-template.logo :src="$document->logo"/>
            @endif
        </div>

        <div class="w-2/3 text-right">
            <div class="space-y-4">
                <div>
                    <h1 class="text-3xl font-light uppercase">{{ $document->header }}</h1>
                    @if ($document->subheader)
                        <p class="text-xs text-gray-600 dark:text-gray-400">{{ $document->subheader }}</p>
                    @endif
                </div>
                <div class="text-xs">
                    <strong class="text-xs block">{{ $document->company->name }}</strong>
                    @if($formattedAddress = $document->company->getFormattedAddressHtml())
                        {!! $formattedAddress !!}
                    @endif
                </div>
            </div>
        </div>
    </x-company.document-template.header>

    <x-company.document-template.metadata class="default-template-metadata space-y-2">
        <div class="flex justify-between items-end">
            <!-- Billing Details -->
            <div class="text-xs">
                <h3 class="text-gray-600 dark:text-gray-400 font-medium mb-1">BILL TO</h3>
                <p class="text-xs font-bold">{{ $document->client->name }}</p>
                @if($formattedAddress = $document->client->getFormattedAddressHtml())
                    {!! $formattedAddress !!}
                @endif
            </div>

            <div class="text-xs">
                <table class="min-w-full">
                    <tbody>
                    <tr>
                        <td class="font-semibold text-right pr-2">{{ $document->label->number }}:</td>
                        <td class="text-left pl-2">{{ $document->number }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold text-right pr-2">{{ $document->label->referenceNumber }}:</td>
                        <td class="text-left pl-2">{{ $document->referenceNumber }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold text-right pr-2">{{ $document->label->date }}:</td>
                        <td class="text-left pl-2">{{ $document->date }}</td>
                    </tr>
                    <tr>
                        <td class="font-semibold text-right pr-2">{{ $document->label->dueDate }}:</td>
                        <td class="text-left pl-2">{{ $document->dueDate }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </x-company.document-template.metadata>

    <!-- Line Items Table -->
    <x-company.document-template.line-items class="default-template-line-items">
        <table class="w-full text-left table-fixed">
            <thead class="text-xs leading-8" style="background: {{ $document->accentColor }}">
            <tr class="text-white">
                <th class="text-left pl-6">{{ $document->columnLabel->items }}</th>
                <th class="text-center">{{ $document->columnLabel->units }}</th>
                <th class="text-right">{{ $document->columnLabel->price }}</th>
                <th class="text-right pr-6">{{ $document->columnLabel->amount }}</th>
            </tr>
            </thead>
            <tbody class="text-xs border-b-2 border-gray-300 leading-8">
            @foreach($document->lineItems as $item)
                <tr>
                    <td class="text-left pl-6 font-semibold">{{ $item->name }}</td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">{{ $item->unitPrice }}</td>
                    <td class="text-right pr-6">{{ $item->subtotal }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot class="text-xs leading-loose">
            <tr>
                <td class="pl-6" colspan="2"></td>
                <td class="text-right font-semibold">Subtotal:</td>
                <td class="text-right pr-6">{{ $document->subtotal }}</td>
            </tr>
            <tr class="text-success-800 dark:text-success-600">
                <td class="pl-6" colspan="2"></td>
                <td class="text-right">Discount (5%):</td>
                <td class="text-right pr-6">({{ $document->discount }})</td>
            </tr>
            <tr>
                <td class="pl-6" colspan="2"></td>
                <td class="text-right">Tax:</td>
                <td class="text-right pr-6">{{ $document->tax }}</td>
            </tr>
            <tr>
                <td class="pl-6" colspan="2"></td>
                <td class="text-right font-semibold border-t">Total:</td>
                <td class="text-right border-t pr-6">{{ $document->total }}</td>
            </tr>
            <tr>
                <td class="pl-6" colspan="2"></td>
                <td class="text-right font-semibold border-t-4 border-double">{{ $document->label->amountDue }}
                    ({{ $document->currencyCode }}):
                </td>
                <td class="text-right border-t-4 border-double pr-6">{{ $document->amountDue }}</td>
            </tr>
            </tfoot>
        </table>
    </x-company.document-template.line-items>

    <!-- Footer Notes -->
    <x-company.document-template.footer class="default-template-footer min-h-48 flex flex-col text-xs p-6">
        <div>
            <h4 class="font-semibold mb-2">Terms & Conditions</h4>
            <p class="break-words line-clamp-4">{{ $document->terms }}</p>
        </div>

        @if($document->footer)
            <div class="mt-auto text-center py-4">
                <p class="font-semibold">{{ $document->footer }}</p>
            </div>
        @endif
    </x-company.document-template.footer>
</x-company.document-template.container>
