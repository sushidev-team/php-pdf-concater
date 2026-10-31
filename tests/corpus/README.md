# External PDF corpus

The manifest pins 15 external PDFs by repository commit, URL, size and SHA-256.
Downloads live under ignored `tmp/pdf-corpus/`; they are not bundled with the
MIT-licensed library. Existing cached bytes are verified before every run.

Sources and attribution:

- [py-pdf/sample-files](https://github.com/py-pdf/sample-files), contributors to
  py-pdf/sample-files. Its README licenses every PDF under CC BY-SA 4.0.
- [PDF Association PDF 2.0 examples](https://github.com/pdf-association/pdf20examples),
  PDF Association and contributors. See its
  [license](https://github.com/pdf-association/pdf20examples/blob/master/LICENSE.md).

These PDFs, their rendered images and PDF derivatives retain their separate
[Creative Commons Attribution-ShareAlike 4.0 International license](https://creativecommons.org/licenses/by-sa/4.0/).
The manifest records exact source URLs and attribution for each file. A merged
test output changes the page order and combines these works; it does not change
the license of the application source. Preserve this notice and manifest when
sharing test outputs. No third-party PDF is relicensed as MIT.

The corpus includes a 117-page mathematics document, embedded fonts, Arabic
CMaps, multiple columns, CMYK and CCITT images, inline images, PNG predictors,
low bit depths, cropped/rotated/scaled pages and PDF 2.0 incremental updates.
Encrypted input and interactive AcroForms are explicit rejection cases.

The supported sources contribute 142 pages. Tests reverse their order and repeat
the first input to detect missing pages, wrong order and resource-ID collisions.
All pages are rendered at 72 DPI with Poppler and compared byte-for-byte to the
same sources rendered on the same runner. Text is compared page-by-page too.
CI also runs `qpdf --check` on each merged output. All three Rust engines, the
Composer process backend and the native PHP backend are checked.

This tests page appearance, text and output structure. It does not certify PDF/A,
PDF/UA, tags, signatures, bookmarks, attachments or interactive behavior. A
successful merge is not a claim of PDF 2.0 or archival conformance.
