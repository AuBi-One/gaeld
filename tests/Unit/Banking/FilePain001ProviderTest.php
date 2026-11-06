<?php

namespace Tests\Unit\Banking;

use App\Domains\Banking\DTOs\PaymentInstructionData;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Services\Payments\FilePain001Provider;
use App\Domains\Banking\Services\SwissBicResolver;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class FilePain001ProviderTest extends TestCase
{
    private function debtor(): BankAccount
    {
        $org = new Organization(['legal_name' => 'Acme SA', 'name' => 'Acme']);
        $ba = new BankAccount([
            'name' => 'Main',
            'iban' => 'CH9300762011623852957',
            'currency' => 'CHF',
        ]);
        $ba->setRelation('organization', $org);

        return $ba;
    }

    private function instruction(string $endToEndId, string $amount, string $currency = 'CHF', ?string $structuredRef = null): PaymentInstructionData
    {
        return new PaymentInstructionData(
            endToEndId: $endToEndId,
            creditorName: 'Supplier AG',
            creditorIban: 'CH4431999123000889012',
            amount: $amount,
            currency: $currency,
            executionDate: Carbon::parse('2026-04-01'),
            structuredReference: $structuredRef,
            unstructuredRemittance: $structuredRef ? null : 'Invoice #42',
            sourceType: 'expense',
            sourceId: 'exp-1',
        );
    }

    public function test_builds_valid_pain001_with_qrr_reference(): void
    {
        $provider = new FilePain001Provider(new SwissBicResolver);
        $result = $provider->initiate($this->debtor(), [
            $this->instruction('GAELD-1', '120.00', 'CHF', '210000000003139471430009017'),
        ]);

        $this->assertTrue($result->isFile());
        $this->assertEquals(1, $result->count);
        $this->assertEquals('120.00', $result->totalAmount);
        $this->assertInstanceOf(Response::class, $result->download);

        $xml = (string) $result->download->getContent();
        $this->assertStringContainsString('urn:iso:std:iso:20022:tech:xsd:pain.001.001.09', $xml);
        $this->assertStringContainsString('<NbOfTxs>1</NbOfTxs>', $xml);
        $this->assertStringContainsString('<CtrlSum>120.00</CtrlSum>', $xml);
        $this->assertStringContainsString('<Prtry>QRR</Prtry>', $xml);
        $this->assertStringContainsString('210000000003139471430009017', $xml);
        $this->assertStringContainsString('<EndToEndId>GAELD-1</EndToEndId>', $xml);
    }

    public function test_uses_scor_for_iso_creditor_reference(): void
    {
        $provider = new FilePain001Provider(new SwissBicResolver);
        $result = $provider->initiate($this->debtor(), [
            $this->instruction('GAELD-2', '50.00', 'CHF', 'RF18539007547034'),
        ]);

        $xml = (string) $result->download->getContent();
        $this->assertStringContainsString('<Cd>SCOR</Cd>', $xml);
        $this->assertStringContainsString('RF18539007547034', $xml);
    }

    public function test_uses_unstructured_remittance_when_no_reference(): void
    {
        $provider = new FilePain001Provider(new SwissBicResolver);
        $result = $provider->initiate($this->debtor(), [
            $this->instruction('GAELD-3', '75.00'),
        ]);

        $xml = (string) $result->download->getContent();
        $this->assertStringContainsString('<Ustrd>Invoice #42</Ustrd>', $xml);
        $this->assertStringNotContainsString('<Prtry>QRR</Prtry>', $xml);
    }

    public function test_aggregates_total_amount(): void
    {
        $provider = new FilePain001Provider(new SwissBicResolver);
        $result = $provider->initiate($this->debtor(), [
            $this->instruction('GAELD-A', '100.00'),
            $this->instruction('GAELD-B', '250.50'),
        ]);

        $this->assertEquals(2, $result->count);
        $this->assertEquals('350.50', $result->totalAmount);
        $xml = (string) $result->download->getContent();
        $this->assertStringContainsString('<NbOfTxs>2</NbOfTxs>', $xml);
        $this->assertStringContainsString('<CtrlSum>350.50</CtrlSum>', $xml);
    }

    public function test_throws_on_empty_batch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FilePain001Provider(new SwissBicResolver))->initiate($this->debtor(), []);
    }

    public function test_throws_on_mixed_currencies(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FilePain001Provider(new SwissBicResolver))->initiate($this->debtor(), [
            $this->instruction('A', '10.00', 'CHF'),
            $this->instruction('B', '10.00', 'EUR'),
        ]);
    }

    public function test_throws_when_debtor_has_no_iban(): void
    {
        $org = new Organization(['legal_name' => 'Acme', 'name' => 'Acme']);
        $ba = new BankAccount(['name' => 'Cash', 'iban' => null, 'currency' => 'CHF']);
        $ba->setRelation('organization', $org);

        $this->expectException(\InvalidArgumentException::class);
        (new FilePain001Provider(new SwissBicResolver))->initiate($ba, [$this->instruction('A', '10.00')]);
    }

    public function test_writes_structured_debtor_and_creditor_addresses(): void
    {
        $debtor = $this->debtor();
        $debtor->organization->fill([
            'address' => 'Rue du Lac 1',
            'postal_code' => '1003',
            'city' => 'Lausanne',
            'country' => 'CH',
        ]);
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-ADR',
            creditorName: 'Supplier AG',
            creditorIban: 'CH4431999123000889012',
            amount: '10.00',
            currency: 'CHF',
            executionDate: Carbon::parse('2026-04-01'),
            structuredReference: null,
            unstructuredRemittance: 'Invoice #42',
            creditorStreet: "Bahnhofstrasse 10\nPostfach",
            creditorPostalCode: '8001',
            creditorTown: 'Zürich',
            creditorCountry: 'ch',
        );

        $xml = (string) (new FilePain001Provider(new SwissBicResolver))
            ->initiate($debtor, [$instr])->download->getContent();
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09');

        $children = fn (string $path) => array_map(
            fn (\DOMNode $n) => $n->nodeName.'='.$n->textContent,
            array_values(array_filter(
                iterator_to_array($xp->query($path)->item(0)->childNodes),
                fn (\DOMNode $n) => $n instanceof \DOMElement,
            )),
        );

        $this->assertSame(
            ['StrtNm=Rue du Lac 1', 'PstCd=1003', 'TwnNm=Lausanne', 'Ctry=CH'],
            $children('//p:Dbtr/p:PstlAdr'),
        );
        $this->assertSame(
            ['StrtNm=Bahnhofstrasse 10, Postfach', 'PstCd=8001', 'TwnNm=Zürich', 'Ctry=CH'],
            $children('//p:Cdtr/p:PstlAdr'),
        );
        // Schema order inside the party: Nm before PstlAdr.
        $this->assertSame('Nm', $xp->query('//p:Cdtr/*[1]')->item(0)->localName);
    }

    public function test_omits_address_without_town_or_country(): void
    {
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-NOADR',
            creditorName: 'Supplier AG',
            creditorIban: 'CH4431999123000889012',
            amount: '10.00',
            currency: 'CHF',
            executionDate: Carbon::parse('2026-04-01'),
            structuredReference: null,
            unstructuredRemittance: 'Invoice #42',
            creditorStreet: 'Bahnhofstrasse 10',
            creditorTown: 'Zürich',
            creditorCountry: null,
        );

        $xml = (string) (new FilePain001Provider(new SwissBicResolver))
            ->initiate($this->debtor(), [$instr])->download->getContent();

        $this->assertStringNotContainsString('<PstlAdr>', $xml);
    }

    public function test_escapes_ampersands_and_keeps_to_the_sps_character_set(): void
    {
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-CHARS',
            creditorName: 'Müller & Söhne',
            creditorIban: 'CH4431999123000889012',
            amount: '10.00',
            currency: 'CHF',
            executionDate: Carbon::parse('2026-04-01'),
            structuredReference: null,
            unstructuredRemittance: 'Note d’honoraires – 2025 ✓',
            creditorBic: 'NOT A BIC',
            creditorTown: 'Zürich',
            creditorCountry: 'CH',
        );

        $xml = (string) (new FilePain001Provider(new SwissBicResolver))
            ->initiate($this->debtor(), [$instr])->download->getContent();
        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09');

        $this->assertSame('Müller & Söhne', $xp->query('//p:Cdtr/p:Nm')->item(0)->textContent);
        $this->assertSame("Note d'honoraires - 2025", $xp->query('//p:Ustrd')->item(0)->textContent);
        // A malformed stored BIC is not written.
        $this->assertStringNotContainsString('NOT A BIC', $xml);
        $this->assertStringNotContainsString('NOTABIC', $xml);
    }

    /** SIX SPS 2025/2026 schema (both versions ship the same file); SIX reserves all rights, so it is not in the repo. */
    private const SPS_SCHEMA_ZIP = 'https://www.six-group.com/dam/download/banking-services/standardization/sps/ig-credit-transfer-xml-schema-sps-2025-en.zip';

    /** sha256 of the schema after the libxml2 change below: a changed file at SIX is noticed. */
    private const SPS_SCHEMA_SHA256 = '1f83e28bc799e052e4f88fa2c82269f9ebb7634a9260f36770eb0189e1fb499b';

    /** Why the schema is not available in this process (download tried once), or null. */
    private static ?string $spsSchemaMissing = null;

    private bool $spsSchemaSkipped = false;

    /**
     * The SIX schema, downloaded once and cached in the temp folder. libxml2 has no character-class
     * subtraction, so "-[\p{C}]" (minus control characters) is dropped; the provider strips them itself.
     * Null when it cannot be had: the test then runs its other checks and ends as skipped (failed in CI).
     */
    private function spsSchema(): ?string
    {
        $dir = sys_get_temp_dir().'/gaeld-sps';
        $path = $dir.'/pain.001.001.09.ch.03.libxml.xsd';
        if (is_file($path) && hash_file('sha256', $path) === self::SPS_SCHEMA_SHA256) {
            return $path;
        }
        if (self::$spsSchemaMissing !== null) {
            return null;
        }

        $zip = @file_get_contents(self::SPS_SCHEMA_ZIP, false, stream_context_create(['http' => ['timeout' => 20]]));
        @mkdir($dir, 0700, true);
        $part = $dir.'/'.getmypid().'.zip';
        $xsd = $zip !== false && file_put_contents($part, $zip) !== false
            ? @file_get_contents('zip://'.$part.'#pain.001.001.09.ch.03.xsd')
            : false;
        @unlink($part);
        if ($xsd === false) {
            self::$spsSchemaMissing = 'could not download or extract the schema from '.self::SPS_SCHEMA_ZIP;

            return null;
        }

        $xsd = str_replace('-[\p{C}]]', ']', $xsd);
        if (hash('sha256', $xsd) !== self::SPS_SCHEMA_SHA256) {
            self::$spsSchemaMissing = 'the schema at '.self::SPS_SCHEMA_ZIP.' changed; check it and update SPS_SCHEMA_SHA256';

            return null;
        }
        file_put_contents($part, $xsd);
        rename($part, $path);

        return $path;
    }

    protected function assertPostConditions(): void
    {
        if ($this->spsSchemaSkipped) {
            $message = 'SIX schema check not run: '.self::$spsSchemaMissing;
            filter_var(getenv('CI'), FILTER_VALIDATE_BOOL) ? $this->fail($message) : $this->markTestSkipped($message);
        }
    }

    private function assertValidSps(string $xml): void
    {
        $schema = $this->spsSchema();
        if ($schema === null) {
            $this->spsSchemaSkipped = true;

            return;
        }

        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        libxml_use_internal_errors(true);
        $valid = $doc->schemaValidate($schema);
        $errors = array_map(fn ($e) => trim($e->message), libxml_get_errors());
        libxml_clear_errors();
        libxml_use_internal_errors(false);
        $this->assertTrue($valid, implode("\n", $errors));
    }

    public function test_domestic_file_follows_the_six_example_and_the_sps_schema(): void
    {
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-DOM',
            creditorName: 'Fiduciaire Exemple SA',
            creditorIban: 'CH93 0076 2011 6238 5295 7',
            amount: '1243.15',
            currency: 'CHF',
            executionDate: Carbon::parse('2026-11-06'),
            structuredReference: null,
            unstructuredRemittance: 'Note d honoraires 2025',
            creditorBic: 'BCVLCH2LXXX',
            creditorStreet: 'Rue du Lac 1',
            creditorPostalCode: '1003',
            creditorTown: 'Lausanne',
            creditorCountry: 'CH',
        );
        $debtor = $this->debtor();
        $debtor->organization->fill(['address' => 'Avenue Exemple 2', 'postal_code' => '1000', 'city' => 'Lausanne', 'country' => 'CH']);
        $debtor->bic = 'BCVLCH2LXXX';

        $xml = (string) (new FilePain001Provider(new SwissBicResolver))
            ->initiate($debtor, [$instr])->download->getContent();
        $this->assertValidSps($xml);

        $doc = new \DOMDocument;
        $doc->loadXML($xml);
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('p', 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09');

        $this->assertStringContainsString('pain.001.001.09.ch.03.xsd', $doc->documentElement->getAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'schemaLocation'));
        $this->assertSame(['NAME', 'VRSN', 'PRVD', 'SPSV'], array_map(
            fn ($n) => $n->textContent,
            iterator_to_array($xp->query('//p:InitgPty/p:CtctDtls/p:Othr/p:ChanlTp')),
        ));
        $this->assertSame('GAELD-DOM', $xp->query('//p:PmtId/p:InstrId')->item(0)?->textContent);
        $this->assertSame(0, $xp->query('//p:ChrgBr')->length);
        $this->assertSame(0, $xp->query('//p:CdtrAgt')->length);
        $this->assertSame(1, $xp->query('//p:DbtrAgt/p:FinInstnId/p:BICFI')->length);
    }

    public function test_foreign_file_keeps_creditor_agent_and_charge_bearer_and_the_sps_schema(): void
    {
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-EUR',
            creditorName: 'Lieferant GmbH',
            creditorIban: 'DE89370400440532013000',
            amount: '50.00',
            currency: 'EUR',
            executionDate: Carbon::parse('2026-11-06'),
            structuredReference: null,
            unstructuredRemittance: 'Rechnung 7',
            creditorBic: 'COBADEFFXXX',
            creditorTown: 'Berlin',
            creditorCountry: 'DE',
        );

        $xml = (string) (new FilePain001Provider(new SwissBicResolver))
            ->initiate($this->debtor(), [$instr])->download->getContent();
        $this->assertValidSps($xml);
        $this->assertStringContainsString('<ChrgBr>SLEV</ChrgBr>', $xml);
        $this->assertStringContainsString('<BICFI>COBADEFFXXX</BICFI>', $xml);
    }

    public function test_unknown_swiss_bank_uses_the_iid_and_unknown_sepa_bank_is_left_out(): void
    {
        $debtor = $this->debtor();
        $debtor->iban = 'CH5699999012345678009'; // IID 99999: not in the curated table
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-NOBIC',
            creditorName: 'Lieferant GmbH',
            creditorIban: 'DE89 3704 0044 0532 0130 00',
            amount: '50.00',
            currency: 'EUR',
            executionDate: Carbon::parse('2026-11-06'),
            structuredReference: null,
            unstructuredRemittance: 'Rechnung 8',
            creditorTown: 'Berlin',
            creditorCountry: 'DE',
        );

        $xml = (string) (new FilePain001Provider(new SwissBicResolver))
            ->initiate($debtor, [$instr])->download->getContent();
        $this->assertValidSps($xml);
        $flat = (string) preg_replace('/>\s+</', '><', $xml);
        $this->assertStringContainsString('<DbtrAgt><FinInstnId><ClrSysMmbId><ClrSysId><Cd>CHBCC</Cd></ClrSysId><MmbId>99999</MmbId></ClrSysMmbId></FinInstnId></DbtrAgt>', $flat);
        $this->assertStringNotContainsString('CdtrAgt', $xml);
        $this->assertStringNotContainsString('NOTPROVIDED', $xml);
        $this->assertStringContainsString('<IBAN>DE89370400440532013000</IBAN>', $xml);
    }

    public function test_payment_abroad_outside_sepa_without_bic_is_refused(): void
    {
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-CHFDE',
            creditorName: 'Lieferant GmbH',
            creditorIban: 'DE89370400440532013000',
            amount: '50.00',
            currency: 'CHF',
            executionDate: Carbon::parse('2026-11-06'),
            structuredReference: null,
            unstructuredRemittance: 'Rechnung 9',
            creditorTown: 'Berlin',
            creditorCountry: 'DE',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Lieferant GmbH');
        (new FilePain001Provider(new SwissBicResolver))->initiate($this->debtor(), [$instr]);
    }

    public function test_eur_payment_to_a_swiss_creditor_is_domestic_not_sepa(): void
    {
        $xml = (string) (new FilePain001Provider(new SwissBicResolver))->initiate($this->debtor(), [
            $this->instruction('GAELD-EURQR', '80.00', 'EUR', '210000000003139471430009017'),
        ])->download->getContent();

        $this->assertValidSps($xml);
        $this->assertStringNotContainsString('<Cd>SEPA</Cd>', $xml);
        $this->assertStringNotContainsString('ChrgBr', $xml);
        $this->assertStringContainsString('<Prtry>QRR</Prtry>', $xml);
    }

    public function test_drops_control_and_format_characters(): void
    {
        $instr = new PaymentInstructionData(
            endToEndId: 'GAELD-SHY',
            creditorName: "Fidu\u{00AD}ciaire\u{200B} SA",
            creditorIban: 'CH4431999123000889012',
            amount: '10.00',
            currency: 'CHF',
            executionDate: Carbon::parse('2026-11-06'),
            structuredReference: null,
            unstructuredRemittance: 'Note',
            creditorTown: 'Lausanne',
            creditorCountry: 'CH',
        );

        $xml = (string) (new FilePain001Provider(new SwissBicResolver))
            ->initiate($this->debtor(), [$instr])->download->getContent();
        $this->assertStringContainsString('<Nm>Fiduciaire SA</Nm>', $xml);
    }
}
