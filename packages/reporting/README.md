# thirdline/reporting

Document rendering shared by the ThirdLine GRC products: branded PDF and XLSX
output from one renderer.

Extracted from the risk product in migration Phase 7.1c.

## Install

```json
{
    "repositories": [{ "type": "path", "url": "packages/reporting" }],
    "require": { "thirdline/reporting": "@dev" }
}
```

`ReportingServiceProvider` is auto-discovered and safe to load unconditionally:
it registers a renderer and changes nothing until something asks for one.

## The renderer does not know what an organisation is

`DocumentRenderer::branding()` used to build its own branding, and in doing so
it read five columns off `App\Models\Organization` — including
`cbn_institution_code`, a Central Bank of Nigeria registration number. That is
one product's idea of an institution. An audit product in another jurisdiction
has no such column and should not acquire the concept by depending on a PDF
renderer.

So the renderer asks:

```php
// A product binds its own.
$this->app->bind(
    ThirdLine\Reporting\Contracts\ResolvesDocumentBranding::class,
    App\Services\Reporting\OrganizationBranding::class,
);
```

A consumer that binds nothing renders **unbranded**, never with another
product's marks. The returned keys are the renderer's vocabulary and all are
optional — `organization_name`, `short_name`, `address`, `logo` (a data URI),
`primary_colour`, `accent_colour`. A product may return extra keys; the PDF
templates read what they were given, which is how `cbn_institution_code` stays
available to the risk product's board pack without existing here.

## What it produces

| Method | Output |
|---|---|
| `pdf($view, $data)` | dompdf, `isRemoteEnabled` off, chrooted to `public/`, DejaVu Sans so ₦ renders |
| `xlsx($headers, $rows, $sheet, $meta)` | a real `.xlsx` — styled header, frozen panes, auto-sized columns |
| `csv($headers, $rows)` | RFC 4180, BOM for Excel |

`isRemoteEnabled` is off deliberately: a report must not make outbound requests
while rendering, which is also why a logo is inlined as a data URI rather than
referenced by path.
