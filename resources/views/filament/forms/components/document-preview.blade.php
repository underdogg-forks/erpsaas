@php
    $data = $this->form->getRawState();
    $document = \App\DTO\DocumentPreviewDTO::fromSettings($this->record, $data);
    $template = $getTemplate();
    $preview = $isPreview();
@endphp

{!! $document->getFontHtml() !!}

<style>
    .doc-template-paper {
        font-family: '{{ $document->font->getLabel() }}', sans-serif;
    }
</style>

<div {{ $attributes }}>
    @include("filament.company.components.document-templates.{$template->value}", [
        'document' => $document,
        'preview' => $preview,
    ])
</div>
