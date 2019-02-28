<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


namespace Cocorico\ListingSearchBundle\Twig;


class ListingSearchExtension extends \Twig_Extension implements \Twig_Extension_GlobalsInterface
{

    /**
     * @param string $listingSearchLocationType
     */
    public function __construct($listingSearchLocationType)
    {
        $this->listingSearchLocationType = $listingSearchLocationType;
    }


    public function getGlobals()
    {
        return array(
            'listingSearchLocationType' => $this->listingSearchLocationType,
        );
    }

    public function getName()
    {
        return 'cocorico_listing_search_extension';
    }
}
