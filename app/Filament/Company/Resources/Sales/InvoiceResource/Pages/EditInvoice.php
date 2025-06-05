<?php

namespace App\Filament\Company\Resources\Sales\InvoiceResource\Pages;

use App\Concerns\HandlePageRedirect;
use App\Concerns\ManagesLineItems;
use App\Filament\Company\Resources\Sales\InvoiceResource;
use App\Models\Accounting\Invoice;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Model;

class EditInvoice extends EditRecord
{
    use HandlePageRedirect;
    use ManagesLineItems;

    protected static string $resource = InvoiceResource::class;

    public function getMaxContentWidth(): MaxWidth | string | null
    {
        return MaxWidth::Full;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invoice $record */
        $lineItems = collect($data['lineItems'] ?? []);

        $this->deleteRemovedLineItems($record, $lineItems);

        $this->handleLineItems($record, $lineItems);

        $totals = $this->updateDocumentTotals($record, $data);

        $data = array_merge($data, $totals);

        $record = parent::handleRecordUpdate($record, $data);

        if ($record->approved_at && $record->approvalTransaction) {
            $record->updateApprovalTransaction();
        }

        return $record;
    }
}
