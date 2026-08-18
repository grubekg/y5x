<?php
declare(strict_types=1);

namespace Grube\Price30\Calc;

/**
 * Der Zustand eines Artikels in einem Markt — die Spalten aus `price_state`.
 *
 * Unveränderlich: Ein Lauf erzeugt einen NEUEN Zustand aus dem alten. So bleibt im Code
 * sichtbar, was sich geändert hat, und ein halb fortgeschriebener Zustand kann nicht
 * entstehen.
 */
final class PromoState
{
    public const NORMAL = 'normal';
    public const PROMO  = 'promo';

    public function __construct(
        public readonly string $mode = self::NORMAL,
        public readonly ?\DateTimeImmutable $promoStarted = null,
        public readonly ?string $prePromoGross = null,
        public readonly ?string $frozenRefNet = null,
        public readonly ?string $frozenRefGross = null,
        /** Warum der letzte Übergang stattfand — landet im Log und auf der Statusseite. */
        public readonly string $lastTransition = '',
    ) {
    }

    public function isPromo(): bool
    {
        return $this->mode === self::PROMO;
    }
}
