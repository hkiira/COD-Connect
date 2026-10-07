<?php

namespace Tests\Unit;

use App\Services\WooCommerce\VariationMatcher;
use PHPUnit\Framework\TestCase;

/** The matching rules that need no database: which local variation carries the options WooCommerce sends. */
class VariationMatcherTest extends TestCase
{
    /** A product in sizes 39/40 and colours Noir/Blanc (four variations). */
    private function variants(): array
    {
        return [
            ['id' => 1, 'options' => ['39', 'noir']],
            ['id' => 2, 'options' => ['40', 'noir']],
            ['id' => 3, 'options' => ['39', 'blanc']],
            ['id' => 4, 'options' => ['40', 'blanc']],
        ];
    }

    private function vocabulary(): array
    {
        return ['39', '40', 'noir', 'blanc'];
    }

    public function test_every_known_option_must_match_one_variation(): void
    {
        $this->assertSame(2, VariationMatcher::uniqueByOptions(['Noir', '40'], $this->variants(), $this->vocabulary()));
        $this->assertSame(3, VariationMatcher::uniqueByOptions([' 39 ', 'BLANC'], $this->variants(), $this->vocabulary()));
    }

    public function test_an_option_in_another_language_is_ignored_but_never_guessed(): void
    {
        // the colour is in Arabic: unknown to the product, so only the size counts and two colours remain
        $this->assertNull(VariationMatcher::uniqueByOptions(['أسود', '40'], $this->variants(), $this->vocabulary()));

        // with a single colour locally, the size alone is enough
        $single = [['id' => 7, 'options' => ['39', 'noir']], ['id' => 8, 'options' => ['40', 'noir']]];
        $this->assertSame(8, VariationMatcher::uniqueByOptions(['أسود', '40'], $single, ['39', '40', 'noir']));
    }

    public function test_nothing_known_means_no_proposal(): void
    {
        $this->assertNull(VariationMatcher::uniqueByOptions(['أسود', 'XL'], $this->variants(), $this->vocabulary()));
        $this->assertNull(VariationMatcher::uniqueByOptions([], $this->variants(), $this->vocabulary()));
        $this->assertNull(VariationMatcher::uniqueByOptions(['41'], $this->variants(), $this->vocabulary()), 'an unknown size');
    }

    public function test_a_combination_that_does_not_exist_locally_is_not_matched(): void
    {
        $variants = [['id' => 1, 'options' => ['39', 'noir']], ['id' => 2, 'options' => ['40', 'blanc']]];

        $this->assertNull(VariationMatcher::uniqueByOptions(['40', 'noir'], $variants, ['39', '40', 'noir', 'blanc']));
    }

    public function test_normalize_trims_and_lowercases(): void
    {
        $this->assertSame('noir', VariationMatcher::normalize("  NOIR\n"));
        $this->assertSame('', VariationMatcher::normalize(null));
    }
}
