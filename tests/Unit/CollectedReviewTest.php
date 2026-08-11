<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Marshmallow\Reviews\Data\CollectedReview;
use Marshmallow\Reviews\Data\ReviewQuery;
use Marshmallow\Reviews\Data\ReviewSummary;
use Marshmallow\Reviews\Support\Html;

it('normalises a rating onto its own scale', function (float $rating, float $scale, float $expected): void {
    $review = new CollectedReview(provider: 'kiyoh', externalId: '1', rating: $rating, ratingScale: $scale);

    expect($review->normalisedRating())->toBe($expected);
})->with([
    'kiyoh out of ten' => [9.0, 10.0, 0.9],
    'google out of five' => [4.0, 5.0, 0.8],
    'the bottom of the scale' => [0.0, 10.0, 0.0],
    'the top of the scale' => [10.0, 10.0, 1.0],
    'above the scale is clamped' => [12.0, 10.0, 1.0],
    'below zero is clamped' => [-2.0, 10.0, 0.0],
    // A zero scale would be a division by zero, so it answers nothing instead.
    'a zero scale' => [9.0, 0.0, 0.0],
    'a negative scale' => [9.0, -5.0, 0.0],
]);

it('knows whether it carries a published response', function (?string $response, bool $expected): void {
    $review = new CollectedReview(provider: 'kiyoh', externalId: '1', rating: 9.0, response: $response);

    expect($review->hasResponse())->toBe($expected);
})->with([
    'a reply' => ['Thank you.', true],
    'no reply' => [null, false],
    'an empty reply' => ['', false],
    'a whitespace reply' => ["\n  ", false],
]);

it('keeps the provider payload it was handed', function (): void {
    $review = new CollectedReview(
        provider: 'kiyoh',
        externalId: '99',
        rating: 8.5,
        reviewedAt: CarbonImmutable::parse('2026-08-01 12:00:00'),
        verified: true,
        raw: ['unmodelled' => 'value'],
    );

    expect($review->raw)->toBe(['unmodelled' => 'value'])
        ->and($review->verified)->toBeTrue()
        ->and($review->reviewedAt?->format('Y-m-d'))->toBe('2026-08-01');
});

it('rescales a summary average onto another maximum', function (): void {
    $summary = new ReviewSummary(provider: 'kiyoh', average: 9.0, scale: 10.0, count: 128);

    expect($summary->normalisedAverage())->toBe(0.9)
        ->and($summary->averageOutOf(5.0))->toBe(4.5)
        ->and($summary->averageOutOf(10.0))->toBe(9.0)
        ->and($summary->averageOutOf(100.0))->toBe(90.0);
});

it('answers zero for a summary with an impossible scale', function (): void {
    $summary = new ReviewSummary(provider: 'kiyoh', average: 9.0, scale: 0.0, count: 1);

    expect($summary->normalisedAverage())->toBe(0.0)
        ->and($summary->averageOutOf(5.0))->toBe(0.0);
});

it('narrows a review query to one product without losing the rest', function (): void {
    $query = new ReviewQuery(limit: 50, since: CarbonImmutable::parse('2026-01-01'), locale: 'nl');

    $narrowed = $query->forProduct('SKU-1');

    expect($narrowed->productIdentifier)->toBe('SKU-1')
        ->and($narrowed->limit)->toBe(50)
        ->and($narrowed->locale)->toBe('nl')
        ->and($narrowed->since?->format('Y-m-d'))->toBe('2026-01-01')
        // Readonly: narrowing hands back a new query rather than mutating one.
        ->and($query->productIdentifier)->toBeNull();
});

it('offers an empty and a since query', function (): void {
    expect(ReviewQuery::all()->limit)->toBeNull()
        ->and(ReviewQuery::all()->since)->toBeNull()
        ->and(ReviewQuery::since(CarbonImmutable::parse('2026-02-03'))->since?->format('Y-m-d'))->toBe('2026-02-03');
});

it('renders raw markup as both renderable and htmlable', function (): void {
    $html = new Html('<span class="badge"></span>');

    expect($html->render())->toBe('<span class="badge"></span>')
        ->and($html->toHtml())->toBe('<span class="badge"></span>')
        ->and((string) $html)->toBe('<span class="badge"></span>');
});
