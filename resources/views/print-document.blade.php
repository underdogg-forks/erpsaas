<!DOCTYPE html>
<html>
<head>
    <title>Invoice #{{ $document->number }}</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">

    <!-- Include Tailwind -->
    <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>

    {!! $document->getFontHtml() !!}

    <style>
        :root {
            color-scheme: light;
        }

        body {
            background-color: white;
            color: black;
        }

        .inv-paper {
            font-family: '{{ $document->font->getLabel() }}', sans-serif;
        }

        @media print {
            body {
                print-color-adjust: exact !important;
                -webkit-print-color-adjust: exact !important;
                margin: 0;
                padding: 0;
            }

            @page {
                size: auto;
                margin: 0;
            }

            .inv-container {
                padding: 0 !important;
                margin: 0 !important;
            }

            .inv-paper {
                box-shadow: none !important;
                border-radius: 0 !important;
                overflow: hidden !important;
                max-height: none !important;
            }
        }
    </style>
</head>
<body class="bg-white">
    @include("filament.infolists.components.document-templates.{$template->value}", [
        'document' => $document,
        'preview' => false,
    ])
</body>
</html>
