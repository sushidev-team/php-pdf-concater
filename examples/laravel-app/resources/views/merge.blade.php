<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SushiDev PDF Concater</title>
    <style>
        body { margin: 0; padding: 4rem 1.5rem; background: #f5f5f2; color: #242c36; font: 17px/1.6 system-ui, sans-serif; }
        main { max-width: 560px; margin: auto; }
        h1 { margin-bottom: .5rem; font-size: 2.5rem; letter-spacing: -.04em; }
        p { color: #586271; }
        form { background: white; padding: 2rem; border: 1px solid #dce0e2; border-radius: 12px; }
        label, input { display: block; margin-bottom: 1rem; }
        input { max-width: 100%; }
        button { background: #235e50; color: white; border: 0; border-radius: 6px; padding: .8rem 1.2rem; font: inherit; cursor: pointer; }
        .errors { color: #a12a20; padding-left: 1.2rem; }
        small { display: block; margin-top: 1rem; color: #586271; }
    </style>
</head>
<body>
<main>
    <h1>SushiDev PDF Concater</h1>
    <p>Choose your PDFs and download one combined document.</p>
    <form action="{{ route('pdf.merge') }}" method="post" enctype="multipart/form-data">
        @csrf
        <label for="files">PDF files</label>
        <input id="files" name="files[]" type="file" accept="application/pdf,.pdf" multiple required>
        @if ($errors->any())
            <ul class="errors" role="alert">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif
        <button type="submit">Merge and download</button>
        <small>2–20 PDFs, up to 10 MB each, subject to your PHP upload limits. Files are merged in upload order.</small>
    </form>
    <small>© {{ now()->year }} <a href="https://sushi.dev">Sushi Dev GmbH</a></small>
</main>
</body>
</html>
