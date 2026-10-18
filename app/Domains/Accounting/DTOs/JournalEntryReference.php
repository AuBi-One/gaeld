<?php

namespace App\Domains\Accounting\DTOs;

/**
 * A record that owns a journal entry (e.g. a salary slip), shown to the user
 * and used to refuse edits of the entry outside its owning feature.
 */
final readonly class JournalEntryReference
{
    public function __construct(
        public string $label,
        public ?string $url = null,
        public ?string $documentUrl = null,
    ) {}

    /**
     * @return array{label: string, url: ?string, documentUrl: ?string}
     */
    public function toArray(): array
    {
        return ['label' => $this->label, 'url' => $this->url, 'documentUrl' => $this->documentUrl];
    }
}
