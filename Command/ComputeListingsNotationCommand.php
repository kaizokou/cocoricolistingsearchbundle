<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cocorico\ListingSearchBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/*
 * Listings platform notation computing command
 * For example every day :
 */

//Cron: 30 2  * * *  user   php app/console cocorico_listing_search:computeNotation

class ComputeListingsNotationCommand extends ContainerAwareCommand
{

    public function configure()
    {
        $this
            ->setName('cocorico_listing_search:computeNotation')
            ->setDescription('Compute Listings Notation.')
            ->setHelp("Usage php app/console cocorico_listing_search:computeNotation");
    }

    public function execute(InputInterface $input, OutputInterface $output)
    {
        $container = $this->getContainer();
        $listingSearchManager = $container->get('cocorico_listing_search.manager');

        $result = $listingSearchManager->computeListingsNotation(
            $this->getContainer()->getParameter('cocorico.locale'),
            $this->getContainer()->getParameter("cocorico.listing_img_min"),
            $this->getContainer()->getParameter("cocorico.user_img_min")
        );

        $output->writeln($result . " listing(s) notation computed");
    }

}
