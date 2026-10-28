<?php

declare(strict_types=1);

namespace App\Domains\Tiss\PreValidation;

use App\Domains\Tiss\PreValidation\Enums\TissValidationSeverity;
use Illuminate\Support\Collection;

final class TissGuideValidationResult
{
    /** @var Collection<int, TissGuideValidationIssue> */
    private Collection $issues;

    public function __construct()
    {
        $this->issues = collect();
    }

    public function add(TissGuideValidationIssue $issue): self
    {
        $this->issues->push($issue);

        return $this;
    }

    /** @param list<TissGuideValidationIssue> $issues */
    public function merge(array $issues): self
    {
        foreach ($issues as $issue) {
            $this->add($issue);
        }

        return $this;
    }

    public function passes(): bool
    {
        return $this->issues->every(fn (TissGuideValidationIssue $i) => $i->isWarning());
    }

    public function hasErrors(): bool
    {
        return $this->issues->contains(fn (TissGuideValidationIssue $i) => $i->isError());
    }

    public function hasWarnings(): bool
    {
        return $this->issues->contains(fn (TissGuideValidationIssue $i) => $i->isWarning());
    }

    public function isEmpty(): bool
    {
        return $this->issues->isEmpty();
    }

    /** @return Collection<int, TissGuideValidationIssue> */
    public function errors(): Collection
    {
        return $this->issues->filter(fn (TissGuideValidationIssue $i) => $i->isError())->values();
    }

    /** @return Collection<int, TissGuideValidationIssue> */
    public function warnings(): Collection
    {
        return $this->issues->filter(fn (TissGuideValidationIssue $i) => $i->isWarning())->values();
    }

    /** @return Collection<int, TissGuideValidationIssue> */
    public function all(): Collection
    {
        return $this->issues->values();
    }

    /** @return list<array<string, mixed>> */
    public function toArray(): array
    {
        return $this->issues->map(fn (TissGuideValidationIssue $i) => $i->toArray())->values()->all();
    }

    public function errorMessages(): string
    {
        return $this->errors()
            ->map(fn (TissGuideValidationIssue $i) => "• {$i->message}")
            ->implode("\n");
    }

    public function severity(): TissValidationSeverity
    {
        if ($this->hasErrors()) {
            return TissValidationSeverity::Error;
        }

        if ($this->hasWarnings()) {
            return TissValidationSeverity::Warning;
        }

        return TissValidationSeverity::Warning;
    }
}
