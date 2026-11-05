<?php

namespace Tests\Unit\Banking;

use App\Domains\Banking\DTOs\PaymentInstructionData;
use App\Domains\Banking\Models\BankAccount;
use App\Domains\Banking\Services\Payments\FilePain001Provider;
use App\Domains\Banking\Services\SwissBicResolver;
use App\Domains\Organizations\Models\Organization;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

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
}
