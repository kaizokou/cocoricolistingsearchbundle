<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cocorico\ListingSearchBundle\Model;

/**
 * ListingSearchable trait.
 *
 * Should be used inside Listing entity, that needs to be searchable through an advanced algorithm.
 */
trait ListingSearchableTrait
{
    /**
     * Auto computed notation
     *
     * @ORM\Column(name="platform_notation", type="decimal", precision=15, scale=14, nullable=true)
     *
     * @var float
     */
    protected $platformNotation = 0;


    /**
     * @return float
     */
    public function getPlatformNotation()
    {
        return $this->platformNotation;
    }

    /**
     * @param float $platformNotation
     */
    public function setPlatformNotation($platformNotation)
    {
        $this->platformNotation = $platformNotation;
    }
}
