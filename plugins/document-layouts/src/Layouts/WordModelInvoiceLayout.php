<?php

namespace Plugins\DocumentLayouts\Layouts;

use App\Domains\Banking\Models\BankAccount;
use App\Domains\Invoicing\Contracts\InvoicePdfLayoutInterface;
use App\Domains\Invoicing\Enums\InvoiceLineType;
use App\Domains\Invoicing\Enums\InvoiceTaxTreatment;
use App\Domains\Invoicing\Enums\InvoiceType;
use App\Domains\Invoicing\Models\Invoice;
use App\Domains\Invoicing\Models\InvoiceLine;
use App\Domains\Organizations\Models\Organization;
use App\Support\Money;
use App\Support\Pdf\PdfFooter;
use Plugins\DocumentLayouts\Support\Carlito;
use Plugins\DocumentLayouts\Support\Format;
use Plugins\DocumentLayouts\Support\Letterhead;
use TCPDF;

/**
 * The invoice pages after AuBi-One's Word model (MODELE_FACTURE.docx): logo in
 * the header, sender and recipient side by side, title and date, the
 * introduction in a grey box, one bordered 5-column table with VAT and "Total TVA incluse",
 * then a box with the bank details, payment terms and thanks. Carlito 11 pt
 * (Calibri metrics). Core adds the QR-bill payment part on the next page, in
 * place of the model's small QR image (Swiss Payment Standards: 46 mm code in
 * the payment part only).
 */
final class WordModelInvoiceLayout implements InvoicePdfLayoutInterface
{
    private const LEFT = 19.05;

    private const WIDTH = 171.9;          // A4 minus 19.05 mm on each side

    private const TOP = 25.0;

    private const BOTTOM = 271.6;         // 297 − 25.4 mm

    private const HEADER_Y = 12.7;

    private const LOGO = 24.0;

    private const RECIPIENT_X = 118.97;   // where the model's tabs (12.49 mm stops) put it

    private const TITLE_COLUMN = 99.2;    // the model's title | date table

    private const LINE = 4.73;            // Calibri 11 pt, single spacing

    private const PAD = 1.76;             // Word cell margin (100 twips)

    private const COLUMNS = [14.2, 74.8, 17.7, 33.2, 31.7];

    private const BANK_COLUMNS = [126.7, 47.1];

    private const BORDER = [217, 217, 217];   // #D9D9D9

    private const SHADE = [239, 239, 239];    // #EFEFEF

    private const LINK = [17, 85, 204];       // #1155CC

    private TCPDF $pdf;

    private string $language = 'fr';

    private float $y = 0;

    public function key(): string
    {
        return 'word-model';
    }

    public function label(): string
    {
        return (string) trans('document-layouts::dl.layout_label');
    }

    public function renderInvoice(TCPDF $pdf, Invoice $invoice, Organization $organization, string $language): void
    {
        $this->pdf = $pdf;
        $this->language = Format::language($language);
        $letterhead = Letterhead::for($organization);

        Carlito::register($pdf);
        $pdf->setCellHeightRatio(1.22);
        $pdf->SetTextColor(0, 0, 0);

        $this->header($letterhead);
        $this->addresses($letterhead, $invoice);
        $this->title($invoice);
        $this->texts($invoice, $organization);
        $this->lines($invoice);
        $this->payment($invoice, $organization, $letterhead);
    }

    private function header(Letterhead $letterhead): void
    {
        $logo = $letterhead->logoFile();
        if ($logo !== null) {
            $this->pdf->Image($logo, self::LEFT, self::HEADER_Y, self::LOGO, self::LOGO, '', '', '', false, 300, '', false, false, 0, 'LT');
        }
        $this->y = self::HEADER_Y + self::LOGO + self::LINE;
    }

    private function addresses(Letterhead $letterhead, Invoice $invoice): void
    {
        $customer = $invoice->customer_snapshot ?? $invoice->customer?->toInvoiceSnapshot($invoice->contactPerson) ?? [];
        $sender = [$letterhead->name, ...$letterhead->addressLines];
        if ($letterhead->phone !== null) {
            $sender[] = $letterhead->phone;
        }
        // Older or partial snapshots may lack keys.
        $country = (string) data_get($customer, 'country', 'CH');
        $recipient = array_values(array_filter([
            data_get($customer, 'name'),
            data_get($customer, 'attention'),
            data_get($customer, 'address'),
            trim(data_get($customer, 'postal_code', '').' '.data_get($customer, 'city', '')),
            $country !== 'CH' ? $country : null,
        ], fn ($line): bool => is_string($line) && trim($line) !== ''));

        $this->font('', 11);
        $y = $this->y;
        foreach ($sender as $line) {
            $this->text(self::LEFT, $y, self::RECIPIENT_X - self::LEFT - 2, $line);
            $y += self::LINE;
        }
        if ($letterhead->email !== null) {
            $this->pdf->SetTextColor(...self::LINK);
            $this->font('U', 11);
            $this->pdf->SetXY(self::LEFT, $y);
            $this->pdf->Cell(0, self::LINE, $letterhead->email, 0, 0, 'L', false, 'mailto:'.$letterhead->email, 0, false, 'T', 'T');
            $this->pdf->SetTextColor(0, 0, 0);
            $this->font('', 11);
            $y += self::LINE;
        }
        $vat = $letterhead->vatNumberWithSuffix($this->language);
        if ($vat !== null) {
            $this->text(self::LEFT, $y, self::RECIPIENT_X - self::LEFT - 2, $this->t('vat_no').' : '.$vat);
            $y += self::LINE;
        }

        $recipientY = $this->y;
        foreach ($recipient as $line) {
            $this->text(self::RECIPIENT_X, $recipientY, self::LEFT + self::WIDTH - self::RECIPIENT_X, $line);
            $recipientY += self::LINE;
        }
        $this->y = max($y, $recipientY) + self::LINE;
    }

    private function title(Invoice $invoice): void
    {
        $this->y += 2 * self::LINE;
        $title = ($invoice->type === InvoiceType::CreditNote ? $this->t('credit_note') : $this->t('invoice')).' '.($invoice->number ?? '');
        $this->font('B', 14);
        $x = self::LEFT + self::PAD;
        $this->text($x, $this->y, self::WIDTH, $title);
        $dateX = max(self::LEFT + self::TITLE_COLUMN, $x + $this->pdf->GetStringWidth($title) + self::PAD) + self::PAD;
        $this->font('', 11);
        $this->text($dateX, $this->y + 0.4, self::LEFT + self::WIDTH - $dateX, $this->t('date').' '.$invoice->issue_date->format('d.m.Y'));
        $this->y += 6.0 + self::LINE;
    }

    private function texts(Invoice $invoice, Organization $organization): void
    {
        $introduction = trim((string) $invoice->introduction);
        if ($introduction !== '') {
            // The model's "Concerne" box: grey, as wide as the table below it.
            $this->font('I', 11);
            $this->multi(self::LEFT, self::WIDTH, $introduction, 'L', self::SHADE, 0.3);
            $this->y += 3;
        }
        $header = trim((string) $organization->invoice_header_text);
        if ($header !== '') {
            $this->font('', 10);
            $this->multi(self::LEFT, self::WIDTH, $header, 'L', null, 0);
            $this->y += 3;
        }
        $this->y += self::LINE - 3;
    }

    private function lines(Invoice $invoice): void
    {
        $this->y += 3;
        $this->tableHeader();
        $position = 0;
        foreach ($invoice->lines as $line) {
            [$quantity, $price, $total] = $this->amounts($line);
            $isText = $line->type === InvoiceLineType::Text;
            // A description taller than a page continues in further rows.
            $this->font($isText ? 'I' : '', 11);
            $this->pdf->setCellPaddings(self::PAD, 0.9, self::PAD, 0.9);
            $parts = $this->split(str_replace(["\r\n", "\r"], "\n", (string) $line->description), self::COLUMNS[1], self::BOTTOM - self::TOP - 7.7 - 1);
            foreach ($parts as $i => $part) {
                $first = $i === 0;
                $this->row([
                    $isText || ! $first ? '' : (string) ++$position,
                    $part,
                    $first ? $quantity : '',
                    $first ? $price : '',
                    $first ? $total : '',
                ], 11, 7.7, $isText ? [1 => 'I'] : []);
            }
        }

        foreach ($this->vatRows($invoice) as [$rate, $amount]) {
            $this->row(['', $this->t('vat'), $rate, '', $amount], 11, 7.7, [], [2 => 10]);
        }
        $hasVat = Money::compare((string) $invoice->vat_amount, '0') !== 0;
        $this->row(
            ['', $hasVat ? $this->t('total_incl_vat') : $this->t('total'), '', '', ($invoice->currency ?? 'CHF').' '.Format::money((string) $invoice->total)],
            11, 7.7, [1 => 'B', 4 => 'B'],
        );
        $this->y += 3;

        if (($invoice->tax_treatment ?? InvoiceTaxTreatment::Standard) === InvoiceTaxTreatment::ReverseCharge) {
            $this->y += 2;
            $this->font('B', 10);
            $this->multi(self::LEFT, self::WIDTH, (string) trans('app.pdf_reverse_charge', [], $this->language), 'L', null, 0);
        }
        $this->y += self::LINE;
    }

    private function tableHeader(): void
    {
        $this->row(
            [$this->t('pos'), $this->t('designation'), $this->t('quantity'), $this->t('unit_price'), $this->t('total')],
            11, 7.7, array_fill(0, 5, 'B'), [2 => 10, 3 => 10], repeatHeader: false, fill: self::SHADE,
        );
    }

    /** @return array{0: string, 1: string, 2: string} quantity, unit price, line total */
    private function amounts(InvoiceLine $line): array
    {
        if ($line->type === InvoiceLineType::Text) {
            return ['', '', ''];
        }
        if ($line->type === InvoiceLineType::Discount && $line->discount_type === 'percentage') {
            return ['', Format::rate((string) $line->unit_price), '-'.Format::money((string) $line->amount)];
        }
        $total = Money::multiply2((string) $line->quantity, (string) $line->unit_price);
        $sign = $line->type === InvoiceLineType::Discount ? '-' : '';

        return [Format::quantity((string) $line->quantity), Format::money((string) $line->unit_price), $sign.Format::money($total)];
    }

    /**
     * One row per VAT rate (from the lines); the invoice's own VAT total when the
     * lines do not add up to it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function vatRows(Invoice $invoice): array
    {
        $byRate = [];
        foreach ($invoice->lines as $line) {
            if ($line->vatRate === null || $line->type === InvoiceLineType::Text) {
                continue;
            }
            $rate = (string) $line->vatRate->rate;
            $byRate[$rate] = Money::add($byRate[$rate] ?? '0.00', (string) ($line->vat_amount ?? '0.00'));
        }
        $vatTotal = (string) $invoice->vat_amount;
        if (Money::compare($vatTotal, '0') === 0 && array_filter($byRate, fn (string $a): bool => Money::compare($a, '0') !== 0) === []) {
            return [];
        }
        $sum = array_reduce($byRate, fn (string $total, string $amount): string => Money::add($total, $amount), '0.00');
        if ($byRate === [] || Money::compare($sum, $vatTotal) !== 0) {
            return [[implode(' / ', array_map(fn ($r): string => Format::rate((string) $r), array_keys($byRate))), Format::money($vatTotal)]];
        }
        ksort($byRate, SORT_NUMERIC);

        return array_map(fn ($rate, string $amount): array => [Format::rate((string) $rate), Format::money($amount)], array_keys($byRate), array_values($byRate));
    }

    private function payment(Invoice $invoice, Organization $organization, Letterhead $letterhead): void
    {
        // The account of the QR-bill: the invoice's own QR-IBAN when set, else the default one.
        $compact = fn (?string $iban): string => strtoupper((string) preg_replace('/\s+/', '', (string) $iban));
        $bank = $invoice->qr_iban
            ? BankAccount::query()->withoutGlobalScopes()->where('organization_id', $organization->id)->get()
                ->first(fn (BankAccount $account): bool => $compact($account->qr_iban) === $compact($invoice->qr_iban))
            : $organization->defaultInvoicingBankAccount();
        $left = [];
        if ($bank instanceof BankAccount) {
            if ($bank->bank_name) {
                $left[] = [$bank->bank_name, '', 12];
            }
            if ($bank->bic) {
                $left[] = ['BIC/SWIFT '.$bank->bic, '', 10];
            }
            $iban = $bank->iban ?: $bank->qr_iban;
            if ($iban) {
                $left[] = ['IBAN '.Format::iban($iban), '', 12];
            }
            $left[] = ['', '', 11];
        } elseif ($invoice->qr_iban) {
            $left[] = ['IBAN '.Format::iban($invoice->qr_iban), '', 12];
            $left[] = ['', '', 11];
        }
        $terms = Format::paymentTerms($invoice->payment_terms, (int) $invoice->issue_date->diffInDays($invoice->due_date, false));
        if ($terms['text'] !== null) {
            $left[] = [$terms['text'], '', 11];
        } elseif ($terms['days'] > 0) {
            $left[] = [$this->t('payment_days', ['days' => $terms['days']]), '', 11];
        }
        $left[] = [$this->t('due_date').' '.$invoice->due_date->format('d.m.Y'), '', 11];
        if ($invoice->qr_reference) {
            $left[] = [$this->t('reference').' '.Format::reference((string) $invoice->qr_reference), '', 10];
        }
        $left[] = [$this->t('thanks'), '', 11];
        $left[] = ['', '', 11];
        $left[] = [$letterhead->name, 'B', 13];

        $height = 2 * 0.8;
        foreach ($left as [$text, $style, $size]) {
            $height += $this->lineHeight($size);
        }
        $this->ensureSpace($height);

        $this->pdf->SetDrawColor(...self::SHADE);
        $this->pdf->SetLineWidth(0.35);
        $this->pdf->Rect(self::LEFT, $this->y, self::BANK_COLUMNS[0], $height);
        $this->pdf->Rect(self::LEFT + self::BANK_COLUMNS[0], $this->y, self::BANK_COLUMNS[1], $height);

        $y = $this->y + 0.8;
        foreach ($left as [$text, $style, $size]) {
            $this->font($style, $size);
            $this->text(self::LEFT + self::PAD, $y, self::BANK_COLUMNS[0] - 2 * self::PAD, $text);
            $y += $this->lineHeight($size);
        }

        // Where the model has its small QR image: a pointer to the payment part.
        $this->font('I', 9);
        $this->pdf->SetTextColor(100, 100, 100);
        $this->pdf->setCellPaddings(self::PAD, 0, self::PAD, 0);
        $this->pdf->MultiCell(self::BANK_COLUMNS[1], $height, $this->t('qr_next_page'), 0, 'C', false, 1,
            self::LEFT + self::BANK_COLUMNS[0], $this->y, true, 0, false, true, $height, 'M');
        $this->pdf->SetTextColor(0, 0, 0);
        $this->y += $height + 3;

        $notes = trim((string) $invoice->notes);
        if ($notes !== '') {
            $this->font('', 10);
            $this->multi(self::LEFT, self::WIDTH, $notes, 'L', null, 0);
            $this->y += 2;
        }
        $footer = trim((string) $organization->invoice_footer_text);
        if ($footer !== '') {
            $this->font('', 9);
            $this->pdf->SetTextColor(100, 100, 100);
            $this->multi(self::LEFT, self::WIDTH, $footer, 'L', null, 0);
            $this->pdf->SetTextColor(0, 0, 0);
        }

        // The model has no footer; the organisation's PDF footer text, when set, goes on every page.
        if (trim((string) $organization->pdf_footer_text) !== '') {
            $this->font('', 8);
            $this->pdf->SetTextColor(100, 100, 100);
            for ($page = 1; $page <= $this->pdf->getNumPages(); $page++) {
                $this->pdf->setPage($page);
                $this->text(self::LEFT, 297 - 12.7 - 3.5, self::WIDTH, PdfFooter::text($organization));
            }
            $this->pdf->lastPage();
            $this->pdf->SetTextColor(0, 0, 0);
        }
    }

    /**
     * A bordered table row; breaks to a new page (and repeats the header row) when it
     * does not fit.
     *
     * @param  list<string>  $cells
     * @param  array<int, string>  $styles  column => B / I
     * @param  array<int, float>  $sizes  column => font size other than $size
     */
    /**
     * @param  array<int, string>  $cells
     * @param  array<int, string>  $styles
     * @param  array<int, float>  $sizes
     * @param  list<int>|null  $fill  RGB
     */
    private function row(array $cells, float $size, float $minHeight, array $styles = [], array $sizes = [], bool $repeatHeader = true, ?array $fill = null): void
    {
        $pad = 0.9;
        $height = $minHeight;
        foreach ($cells as $i => $text) {
            $this->font($styles[$i] ?? '', $sizes[$i] ?? $size);
            $this->pdf->setCellPaddings(self::PAD, $pad, self::PAD, $pad);
            $height = max($height, $this->pdf->getStringHeight(self::COLUMNS[$i], $text));
        }
        if ($this->y + $height > self::BOTTOM) {
            $this->pdf->AddPage();
            $this->y = self::TOP;
            if ($repeatHeader) {
                $this->tableHeader();
            }
        }

        // Borders and shades are drawn as rectangles: TCPDF puts a MultiCell's own border and
        // fill at the start of the page, under core's white page background.
        $this->pdf->SetDrawColor(...self::BORDER);
        $this->pdf->SetLineWidth(0.35);
        if ($fill !== null) {
            $this->pdf->SetFillColor(...$fill);
        }
        $x = self::LEFT;
        foreach ($cells as $i => $text) {
            $this->pdf->Rect($x, $this->y, self::COLUMNS[$i], $height, $fill !== null ? 'DF' : 'D');
            $this->font($styles[$i] ?? '', $sizes[$i] ?? $size);
            $this->pdf->setCellPaddings(self::PAD, $pad, self::PAD, $pad);
            $align = $i >= 2 ? 'R' : 'L';
            $this->pdf->MultiCell(self::COLUMNS[$i], $height, $text, 0, $align, false, 0, $x, $this->y, true, 0, false, false, $height, 'M');
            $x += self::COLUMNS[$i];
        }
        $this->y += $height;
    }

    /**
     * Text wrapped over the width from the current position (with an optional shade).
     *
     * @param  list<int>|null  $fill  RGB
     */
    private function multi(float $x, float $width, string $text, string $align, ?array $fill, float $pad): void
    {
        $this->pdf->setCellPaddings(self::PAD, $pad, self::PAD, $pad);
        foreach ($this->split($text, $width, self::BOTTOM - self::TOP) as $part) {
            $height = $this->pdf->getStringHeight($width, $part);
            $this->ensureSpace($height);
            if ($fill !== null) {
                $this->pdf->SetFillColor(...$fill);
                $this->pdf->Rect($x, $this->y, $width, $height, 'F');
            }
            $this->pdf->MultiCell($width, $height, $part, 0, $align, false, 1, $x, $this->y, true, 0, false, false, $height, 'T');
            $this->y += $height;
        }
    }

    /**
     * The text in pieces that each fit `$maxHeight` with the current font and paddings
     * (at word boundaries), so nothing runs past the bottom of a page. Quadratic in the
     * words of an over-long text (rare); a single word taller than a page is not split.
     *
     * @return list<string>
     */
    private function split(string $text, float $width, float $maxHeight): array
    {
        if ($this->pdf->getStringHeight($width, $text) <= $maxHeight) {
            return [$text];
        }
        $parts = [];
        $current = '';
        foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $token) {
            if (trim($current) !== '' && $this->pdf->getStringHeight($width, rtrim($current.$token)) > $maxHeight) {
                $parts[] = rtrim($current);
                $current = ltrim($token);
            } else {
                $current .= $token;
            }
        }
        if (trim($current) !== '') {
            $parts[] = rtrim($current);
        }

        return $parts;
    }

    private function text(float $x, float $y, float $width, string $text): void
    {
        $this->pdf->setCellPaddings(0, 0, 0, 0);
        $this->pdf->SetXY($x, $y);
        $this->pdf->Cell($width, self::LINE, $text, 0, 0, 'L', false, '', 1, false, 'T', 'T');
    }

    private function ensureSpace(float $height): void
    {
        if ($this->y + $height > self::BOTTOM) {
            $this->pdf->AddPage();
            $this->y = self::TOP;
        }
    }

    private function font(string $style, float $size): void
    {
        $this->pdf->SetFont(Carlito::FAMILY, $style, $size);
    }

    private function lineHeight(float $size): float
    {
        return $size * 1.22 * 25.4 / 72;
    }

    /** @param  array<string, string|int>  $replace */
    private function t(string $key, array $replace = []): string
    {
        return (string) trans('document-layouts::dl.'.$key, $replace, $this->language);
    }
}
