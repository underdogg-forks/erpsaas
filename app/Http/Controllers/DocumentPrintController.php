<?php

namespace App\Http\Controllers;

use App\DTO\DocumentDTO;
use App\Enums\Accounting\DocumentType;
use App\Enums\Setting\Template;
use App\Models\Accounting\Estimate;
use App\Models\Accounting\Invoice;
use App\Models\Accounting\RecurringInvoice;
use App\Models\Setting\DocumentDefault;
use Illuminate\Http\Request;

class DocumentPrintController extends Controller
{
    protected array $documentModels = [
        'invoice' => Invoice::class,
        'recurring_invoice' => RecurringInvoice::class,
        'estimate' => Estimate::class,
    ];

    public function show(Request $request, string $documentType, int $id)
    {
        if (! isset($this->documentModels[$documentType])) {
            abort(404, "Invalid document type: {$documentType}");
        }

        $modelClass = $this->documentModels[$documentType];
        $document = $modelClass::findOrFail($id);
        $documentTypeEnum = $document::documentType();

        if ($documentTypeEnum === DocumentType::RecurringInvoice) {
            $documentTypeEnum = DocumentType::Invoice;
        }

        $defaults = DocumentDefault::query()
            ->type($documentTypeEnum)
            ->first();

        $template = $defaults?->template ?? Template::Default;
        $document = DocumentDTO::fromModel($document);

        return view('print-document', [
            'document' => $document,
            'template' => $template,
        ]);
    }
}
