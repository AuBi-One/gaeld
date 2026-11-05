<?php

namespace App\Domains\Banking\Services\Payments;

use App\Domains\Banking\Contracts\PaymentInitiationProviderInterface;
use App\Domains\Banking\DTOs\PaymentInitiationResult;
use App\Domains\Banking\DTOs\PaymentInstructionData;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Services\SwissBicResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Generates an ISO 20022 pain.001.001.09 (Customer Credit Transfer Initiation)
 * XML file the user uploads to their e-banking.
 *
 * Default CE provider — superseded by BlinkPaymentProvider when the EE
 * bank_sync feature is enabled and the BankAccount has sync_provider=blink.
 */
class FilePain001Provider implements PaymentInitiationProviderInterface
{
    private const NAMESPACE_URI = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';

    public function __construct(private readonly SwissBicResolver $bicResolver) {}

    /**
     * @param  PaymentInstructionData[]  $instructions
     */
    public function initiate(BankAccount $debtor, array $instructions): PaymentInitiationResult
    {
        if ($instructions === []) {
            throw new \InvalidArgumentException('Cannot generate pain.001 with no instructions.');
        }

        if (! $debtor->iban) {
            throw new \InvalidArgumentException("Debtor bank account {$debtor->id} has no IBAN.");
        }

        $currency = $instructions[0]->currency;
        foreach ($instructions as $instr) {
            if ($instr->currency !== $currency) {
                throw new \InvalidArgumentException('All instructions in a batch must share the same currency.');
            }
        }

        $xml = $this->build($debtor, $instructions, $currency);
        $totalAmount = $this->controlSum($instructions);
        $filename = 'pain001-'.preg_replace('/[^A-Z0-9]/i', '', $debtor->iban).'-'.Carbon::now()->format('YmdHis').'.xml';

        $response = new Response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);

        return PaymentInitiationResult::file($response, count($instructions), $totalAmount, $currency);
    }

    /**
     * @param  PaymentInstructionData[]  $instructions
     */
    private function build(BankAccount $debtor, array $instructions, string $currency): string
    {
        $org = $debtor->organization;
        $now = Carbon::now();
        $msgId = $this->messageId($org->id ?? 'NA');
        $pmtInfId = 'PMT-'.Str::upper(Str::random(12));
        $controlSum = $this->controlSum($instructions);
        $execDate = $instructions[0]->executionDate->toDateString();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NAMESPACE_URI, 'Document');
        $dom->appendChild($root);

        $cstmr = $dom->createElement('CstmrCdtTrfInitn');
        $root->appendChild($cstmr);

        // ── Group Header ──
        $grpHdr = $dom->createElement('GrpHdr');
        $cstmr->appendChild($grpHdr);
        $grpHdr->appendChild($this->textElement($dom, 'MsgId', $msgId));
        $grpHdr->appendChild($this->textElement($dom, 'CreDtTm', $now->toIso8601String()));
        $grpHdr->appendChild($this->textElement($dom, 'NbOfTxs', (string) count($instructions)));
        $grpHdr->appendChild($this->textElement($dom, 'CtrlSum', $controlSum));

        $initgPty = $dom->createElement('InitgPty');
        $initgPty->appendChild($this->textElement($dom, 'Nm', $this->sanitize($org->legal_name ?: $org->name)));
        $grpHdr->appendChild($initgPty);

        // ── Payment Information (one block, single currency / single execution date) ──
        $pmtInf = $dom->createElement('PmtInf');
        $cstmr->appendChild($pmtInf);
        $pmtInf->appendChild($this->textElement($dom, 'PmtInfId', $pmtInfId));
        $pmtInf->appendChild($this->textElement($dom, 'PmtMtd', 'TRF'));
        $pmtInf->appendChild($this->textElement($dom, 'BtchBookg', 'true'));
        $pmtInf->appendChild($this->textElement($dom, 'NbOfTxs', (string) count($instructions)));
        $pmtInf->appendChild($this->textElement($dom, 'CtrlSum', $controlSum));

        // PmtTpInf is omitted for SPS Type 3 (Swiss-domestic CHF/EUR) which covers
        // the vast majority of payments. SEPA SvcLvl is EUR-only and would cause
        // strict banks (e.g. UBS) to reject CHF payments with "format invalide".
        // Only emit SvcLvl/SEPA when the payment is actually a SEPA EUR payment
        // from a CH/LI debtor to a non-CH/LI SEPA-zone creditor.
        $pmtTpInf = $this->buildPmtTpInf($dom, $debtor, $instructions, $currency);
        if ($pmtTpInf !== null) {
            $pmtInf->appendChild($pmtTpInf);
        }

        // pain.001.001.09: ReqdExctnDt is element-only and must wrap the date in <Dt> (or <DtTm>).
        $reqdExctnDt = $dom->createElement('ReqdExctnDt');
        $reqdExctnDt->appendChild($this->textElement($dom, 'Dt', $execDate));
        $pmtInf->appendChild($reqdExctnDt);

        $dbtr = $dom->createElement('Dbtr');
        $dbtr->appendChild($this->textElement($dom, 'Nm', $this->sanitize($org->legal_name ?: $org->name)));
        $dbtrAdr = $this->buildPostalAddress($dom, $org->address, $org->postal_code, $org->city, $org->country);
        if ($dbtrAdr !== null) {
            $dbtr->appendChild($dbtrAdr);
        }
        $pmtInf->appendChild($dbtr);

        $dbtrAcct = $dom->createElement('DbtrAcct');
        $dbtrAcctId = $dom->createElement('Id');
        $dbtrAcctId->appendChild($this->textElement($dom, 'IBAN', $this->normalizeIban($debtor->iban)));
        $dbtrAcct->appendChild($dbtrAcctId);
        $pmtInf->appendChild($dbtrAcct);

        $dbtrAgt = $dom->createElement('DbtrAgt');
        $dbtrAgt->appendChild($this->buildFinInstnId($dom, $debtor->iban, $debtor->bic));
        $pmtInf->appendChild($dbtrAgt);

        $pmtInf->appendChild($this->textElement($dom, 'ChrgBr', 'SLEV'));

        // ── Credit Transfer Transactions ──
        foreach ($instructions as $instr) {
            $pmtInf->appendChild($this->buildTransaction($dom, $instr));
        }

        return $dom->saveXML() ?: '';
    }

    /**
     * Structured postal address (PostalAddress24), as SPS 2025 requires for the debtor and the
     * creditor: town and country are mandatory, so without them no address is written.
     * Element order follows the schema: StrtNm, PstCd, TwnNm, Ctry.
     */
    private function buildPostalAddress(
        \DOMDocument $dom,
        ?string $street,
        ?string $postalCode,
        ?string $town,
        ?string $country,
    ): ?\DOMElement {
        $town = trim((string) $town);
        $country = strtoupper(trim((string) $country));
        if ($town === '' || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return null;
        }

        $adr = $dom->createElement('PstlAdr');
        $street = trim((string) preg_replace('/\s*[\r\n]+\s*/', ', ', (string) $street));
        if ($street !== '') {
            $adr->appendChild($this->textElement($dom, 'StrtNm', $this->sanitize($street, 70)));
        }
        $postalCode = trim((string) $postalCode);
        if ($postalCode !== '') {
            $adr->appendChild($this->textElement($dom, 'PstCd', $this->sanitize($postalCode, 16)));
        }
        $adr->appendChild($this->textElement($dom, 'TwnNm', $this->sanitize($town, 35)));
        $adr->appendChild($this->textElement($dom, 'Ctry', $country));

        return $adr;
    }

    private function buildTransaction(\DOMDocument $dom, PaymentInstructionData $instr): \DOMElement
    {
        $tx = $dom->createElement('CdtTrfTxInf');

        $pmtId = $dom->createElement('PmtId');
        $pmtId->appendChild($this->textElement($dom, 'EndToEndId', $this->sanitize($instr->endToEndId, 35)));
        $tx->appendChild($pmtId);

        $amt = $dom->createElement('Amt');
        $instdAmt = $this->textElement($dom, 'InstdAmt', $instr->amount);
        $instdAmt->setAttribute('Ccy', $instr->currency);
        $amt->appendChild($instdAmt);
        $tx->appendChild($amt);

        $cdtrAgt = $dom->createElement('CdtrAgt');
        $cdtrAgt->appendChild($this->buildFinInstnId($dom, $instr->creditorIban, $instr->creditorBic));
        $tx->appendChild($cdtrAgt);

        $cdtr = $dom->createElement('Cdtr');
        $cdtr->appendChild($this->textElement($dom, 'Nm', $this->sanitize($instr->creditorName, 70)));
        $cdtrAdr = $this->buildPostalAddress(
            $dom,
            $instr->creditorStreet,
            $instr->creditorPostalCode,
            $instr->creditorTown,
            $instr->creditorCountry,
        );
        if ($cdtrAdr !== null) {
            $cdtr->appendChild($cdtrAdr);
        }
        $tx->appendChild($cdtr);

        $cdtrAcct = $dom->createElement('CdtrAcct');
        $cdtrAcctId = $dom->createElement('Id');
        $cdtrAcctId->appendChild($this->textElement($dom, 'IBAN', $instr->creditorIban));
        $cdtrAcct->appendChild($cdtrAcctId);
        $tx->appendChild($cdtrAcct);

        // Remittance: structured (QR-bill) > SCOR > unstructured
        $rmtInf = $dom->createElement('RmtInf');
        if ($instr->isQrReference()) {
            $strd = $dom->createElement('Strd');
            $cdtrRefInf = $dom->createElement('CdtrRefInf');
            $tp = $dom->createElement('Tp');
            $cdOrPrtry = $dom->createElement('CdOrPrtry');
            $prtry = $this->textElement($dom, 'Prtry', 'QRR');
            $cdOrPrtry->appendChild($prtry);
            $tp->appendChild($cdOrPrtry);
            $cdtrRefInf->appendChild($tp);
            $cdtrRefInf->appendChild($this->textElement($dom, 'Ref', $instr->structuredReference));
            $strd->appendChild($cdtrRefInf);
            $rmtInf->appendChild($strd);
        } elseif ($instr->isScorReference()) {
            $strd = $dom->createElement('Strd');
            $cdtrRefInf = $dom->createElement('CdtrRefInf');
            $tp = $dom->createElement('Tp');
            $cdOrPrtry = $dom->createElement('CdOrPrtry');
            $cd = $this->textElement($dom, 'Cd', 'SCOR');
            $cdOrPrtry->appendChild($cd);
            $tp->appendChild($cdOrPrtry);
            $cdtrRefInf->appendChild($tp);
            $cdtrRefInf->appendChild($this->textElement($dom, 'Ref', $instr->structuredReference));
            $strd->appendChild($cdtrRefInf);
            $rmtInf->appendChild($strd);
        } elseif ($instr->unstructuredRemittance) {
            $rmtInf->appendChild($this->textElement($dom, 'Ustrd', $this->sanitize($instr->unstructuredRemittance, 140)));
        }
        if ($rmtInf->hasChildNodes()) {
            $tx->appendChild($rmtInf);
        }

        return $tx;
    }

    /**
     * @param  PaymentInstructionData[]  $instructions
     */
    private function controlSum(array $instructions): string
    {
        $sum = 0.0;
        foreach ($instructions as $instr) {
            $sum += (float) $instr->amount;
        }

        return number_format($sum, 2, '.', '');
    }

    private function messageId(string $orgId): string
    {
        $short = substr(str_replace('-', '', $orgId), 0, 8);

        return 'GAELD-'.$short.'-'.Carbon::now()->format('YmdHis').'-'.Str::upper(Str::random(4));
    }

    private function normalizeIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    /**
     * Build a `<FinInstnId>` element. Emits `<BICFI>` when a BIC is known
     * (explicit or derived from a Swiss IID), otherwise falls back to
     * `<Othr><Id>NOTPROVIDED</Id></Othr>` per ISO 20022.
     *
     * Some banks (notably UBS) enforce a strict XSD profile that only
     * accepts `<BICFI>` here; users on such banks must populate the BIC
     * field on their bank account / supplier contact.
     */
    private function buildFinInstnId(\DOMDocument $dom, ?string $iban, ?string $explicitBic): \DOMElement
    {
        $finInstnId = $dom->createElement('FinInstnId');
        $bic = $this->resolveBic($iban, $explicitBic);

        if ($bic !== null) {
            $finInstnId->appendChild($this->textElement($dom, 'BICFI', $bic));

            return $finInstnId;
        }

        $othr = $dom->createElement('Othr');
        $othr->appendChild($this->textElement($dom, 'Id', 'NOTPROVIDED'));
        $finInstnId->appendChild($othr);

        return $finInstnId;
    }

    /**
     * Resolve a BIC for a given (optional) IBAN + explicit BIC. Explicit BIC
     * always wins. When absent, falls back to a curated CH/LI IID lookup that
     * only returns a BIC when the issuing institution is unambiguous; returns
     * `null` otherwise so the caller can emit `Othr/NOTPROVIDED`.
     */
    private function resolveBic(?string $iban, ?string $explicitBic): ?string
    {
        // Derived from a Swiss/LI IBAN via the curated IID table: always right for that IBAN.
        // Only returns a BIC when the IID is unambiguously known.
        $derived = $this->bicResolver->resolveFromIban($iban);
        if ($derived !== null) {
            return $derived;
        }

        // A stored BIC only when well-formed: a malformed one fails the schema and the bank
        // rejects the whole file.
        $normalized = strtoupper(preg_replace('/\s+/', '', (string) $explicitBic) ?? '');

        return preg_match('/^[A-Z0-9]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $normalized) === 1
            ? $normalized
            : null;
    }

    /**
     * Build PmtTpInf only when a service level is actually required.
     *
     * Per Swiss Payment Standards:
     *  - Type 1 (SEPA): SvcLvl/SEPA, EUR only, debtor in CH/LI, creditor in SEPA zone.
     *  - Type 3 (Swiss-domestic): no SvcLvl, no LclInstrm.
     *  - Type 2 (foreign): no SvcLvl.
     *
     * @param  PaymentInstructionData[]  $instructions
     */
    private function buildPmtTpInf(
        \DOMDocument $dom,
        BankAccount $debtor,
        array $instructions,
        string $currency,
    ): ?\DOMElement {
        if ($currency !== 'EUR') {
            return null;
        }

        $debtorCountry = strtoupper(substr($this->normalizeIban($debtor->iban ?? ''), 0, 2));
        if (! in_array($debtorCountry, ['CH', 'LI'], true)) {
            return null;
        }

        // All creditors must sit in the SEPA zone for the SEPA service level
        // to be valid. If any single creditor is non-SEPA, drop SvcLvl.
        foreach ($instructions as $instr) {
            $cdtrCountry = strtoupper(substr($this->normalizeIban($instr->creditorIban), 0, 2));
            if (! $this->isSepaCountry($cdtrCountry)) {
                return null;
            }
        }

        $pmtTpInf = $dom->createElement('PmtTpInf');
        $svcLvl = $dom->createElement('SvcLvl');
        $svcLvl->appendChild($this->textElement($dom, 'Cd', 'SEPA'));
        $pmtTpInf->appendChild($svcLvl);

        return $pmtTpInf;
    }

    private function isSepaCountry(string $cc): bool
    {
        // SEPA member countries (EU + EEA + UK + CH + LI + MC + SM + AD + VA).
        static $sepa = [
            'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR',
            'GR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL',
            'PT', 'RO', 'SE', 'SI', 'SK', 'IS', 'LI', 'NO', 'CH', 'GB', 'MC',
            'SM', 'AD', 'VA',
        ];

        return in_array($cc, $sepa, true);
    }

    /**
     * Element with text content. DOMDocument::createElement($name, $value) does not escape "&"
     * (it silently drops the entity), so the text goes in as a text node.
     */
    private function textElement(\DOMDocument $dom, string $name, string $value): \DOMElement
    {
        $element = $dom->createElement($name);
        $element->appendChild($dom->createTextNode($value));

        return $element;
    }

    /**
     * Strip control chars, keep to the SPS character set and trim to ISO 20022 max length.
     */
    private function sanitize(string $value, int $maxLength = 70): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? $value;
        // SPS allows Latin only: map common typographic characters, drop the rest.
        $clean = strtr($clean, ['’' => "'", '‘' => "'", '‚' => "'", '“' => '"', '”' => '"', '„' => '"', '–' => '-', '—' => '-', '…' => '...']);
        $clean = preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{017F}\x{0218}-\x{021B}\x{20AC}]/u', '', $clean) ?? $clean;
        $clean = trim($clean);

        return mb_substr($clean, 0, $maxLength);
    }
}
