function printPdf(url, title) {
    if (title) {
        document.title = title;
    }

    const iframe = document.createElement('iframe');
    iframe.style.visibility = 'hidden';
    iframe.style.position = 'absolute';
    iframe.style.width = '0';
    iframe.style.height = '0';
    iframe.src = url;
    document.body.appendChild(iframe);

    iframe.onload = function () {
        try {
            iframe.contentWindow.print();
        } catch (e) {
            console.error('Error printing PDF:', e);
        }
    };
}
