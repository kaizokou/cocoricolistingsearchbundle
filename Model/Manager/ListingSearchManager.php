<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cocorico\ListingSearchBundle\Model\Manager;


use Cocorico\CoreBundle\Entity\Booking;
use Cocorico\CoreBundle\Entity\Listing;
use Cocorico\CoreBundle\Repository\BookingRepository;
use Cocorico\CoreBundle\Repository\ListingRepository;
use Cocorico\MessageBundle\Model\ThreadManager;
use Doctrine\ORM\EntityManager;

class ListingSearchManager
{

    protected $em;
    protected $threadManager;
    protected $weights;

    /**
     * @param EntityManager $em
     * @param ThreadManager $threadManager
     * @param array         $weights
     */
    public function __construct(EntityManager $em, ThreadManager $threadManager, array $weights)
    {
        $this->em = $em;
        $this->threadManager = $threadManager;
        $this->weights = $weights;
    }

    /**
     * @param string $locale
     * @param int    $minListingImages
     * @param int    $minUserImages
     *
     * @return int
     */
    public function computeListingsNotation($locale, $minListingImages, $minUserImages)
    {
        /** @var ListingRepository $bookingRepository */
        $listingRepository = $this->em->getRepository('CocoricoCoreBundle:Listing');
        /** @var Listing[] $listings */
        $listings = $listingRepository->findBy(array("status" => Listing::STATUS_PUBLISHED));
        $globalTotalWeights =
            array_sum($this->weights) - $this->weights["listing_random_bonus"] - $this->weights["listing_new_bonus"];
        $nbVotesWeight = 3;//Significant nb votes
        $maxRating = 5;
        $nbListings = count($listings);
        //Random bonus
        $luckyListings = array_rand($listings, ceil(0.05 * $nbListings));
        if (!is_array($luckyListings)) {
            $luckyListings = array($luckyListings);
        }

        foreach ($listings as $i => $listing) {
            $totalWeights = $globalTotalWeights;
            $offerer = $listing->getUser();
            //----Admin notation
            $adminNotation = $listing->getAdminNotation() / 10;

            //----Listing fill rate
            $listingFillRate = array_sum($listing->getCompletionInformations($minListingImages, false)) / 5;

            //----User fill rate
            $userFillRate = array_sum($offerer->getCompletionInformations($minUserImages, false)) / 2;

            //----Rating: Rating value depends on number of votes
            $nbVotes = $listing->getCommentCount();
            $avgRating = $listing->getAverageRating();
            //More votes more the average rating is equal to the average rating
            $avgRating = (($avgRating * (1 - exp(-$maxRating * $nbVotes / $nbVotesWeight)) * 100) / 100) / 5;

            //----Calendar update
            $availabilitiesUpdatedAt = $listing->getAvailabilitiesUpdatedAt();
            $nbCalDays = 0;
            if ($availabilitiesUpdatedAt) {
                $now = new \DateTime();
                $nbCalDays = intval($availabilitiesUpdatedAt->diff($now)->format("%a"));
                $nbCalDays = $this->getNote("calDays", $nbCalDays);
            }

            //----Nb Bookings in last 30 days
            /** @var BookingRepository $bookingRepository */
            $bookingRepository = $this->em->getRepository('CocoricoCoreBundle:Booking');
            $now = new \DateTime();
            $lastBookings = $bookingRepository->findByListingAndLastCreated(
                $listing->getId(),
                $locale,
                array(Booking::STATUS_PAYED),
                $now->modify('-1 month')
            );
            $lastBookings = $this->getNote("lastBookings", count($lastBookings));

            //----Answers Ratings
            $answersRateAndDelay = $this->threadManager->getReplyRateAndDelay($offerer);
            $answersRate = $answersRateAndDelay["reply_rate"];

            //----Answers delay
            $answersDelay = $this->getNote("answersDelay", $answersRateAndDelay["reply_delay"]);

            $nbBookings = count($listing->getBookings());
            //----Acceptation Rate
            $bookingsAcceptedRate = 0;
            if ($nbBookings) {
                $bookingsAccepted = $bookingRepository->findByListingAndPayed(
                    $listing->getId(),
                    $locale
                );
                $bookingsAcceptedRate = count($bookingsAccepted) / $nbBookings;
            }

            //----bank Wires Rate
            $bankWiresRate = 0;
            if ($nbBookings) {
                $bookingsValidated = $bookingRepository->findByListingAndValidated(
                    $listing->getId(),
                    $locale
                );
                $bankWiresRate = (count($bookingsValidated) / $nbBookings);
            }

            //----Certified
            $certified = intval($listing->getCertified());

            //----Bonus new listing
            $listingCreatedAt = $listing->getCreatedAt();
            $now = new \DateTime();
            $listingNewBonus = 0;
            $newListingNbDays = intval($listingCreatedAt->diff($now)->format("%a"));
            if ($newListingNbDays <= 30) {
                $listingNewBonus = 1;
                $totalWeights += $this->weights["listing_new_bonus"];
            }

            //----Random Bonus
            $randomBonus = 0;
            if (in_array($i, $luckyListings)) {
                $randomBonus = mt_rand(1, 5) / 5;
                $totalWeights += $this->weights["listing_random_bonus"];
            }


            $notation = $adminNotation * $this->weights["listing_admin_notation"] +
                $listingFillRate * $this->weights["listing_fill_rate"] +
                $userFillRate * $this->weights["offerer_fill_rate"] +
                $avgRating * $this->weights["listing_avg_rating"] +
                $nbCalDays * $this->weights["listing_availabilities_updated"] +
                $lastBookings * $this->weights["listing_last_bookings"] +
                $answersRate * $this->weights["offerer_answers_rate"] +
                $bookingsAcceptedRate * $this->weights["offerer_acceptation_rate"] +
                $bankWiresRate * $this->weights["offerer_nb_bank_wires"] +
                $answersDelay * $this->weights["offerer_answers_delay"] +
                $certified * $this->weights["listing_certified"] +
                $listingNewBonus * $this->weights["listing_new_bonus"] +
                $randomBonus * $this->weights["listing_random_bonus"];


            $notation = $notation / $totalWeights;

            //Save notation
            $listing->setPlatformNotation($notation);

            // Save Answer delay
            $offerer->setAnswerDelay($answersRateAndDelay["reply_delay"]);

            // persist values
            $this->em->persist($listing);
            $this->em->persist($offerer);

//            echo("listing:" . $listing->getId() . PHP_EOL);
//            echo("globalTotalWeights:" . $globalTotalWeights . PHP_EOL);
//            echo("listing_admin_notation:" . $adminNotation . PHP_EOL);
//            echo("listing_fill_rate:" . $listingFillRate . PHP_EOL);
//            echo("offerer_fill_rate:" . $userFillRate . PHP_EOL);
//            echo("listing_avg_rating:" . $avgRating . PHP_EOL);
//            echo("listing_availabilities_updated:" . $nbCalDays . PHP_EOL);
//            echo("listing_last_bookings:" . $lastBookings . PHP_EOL);
//            echo("offerer_answers_rate:" . $answersRate . PHP_EOL);
//            echo("offerer_acceptation_rate:" . $bookingsAcceptedRate . PHP_EOL);
//            echo("offerer_nb_bank_wires:" . $bankWiresRate . PHP_EOL);
//            echo("offerer_answers_delay:" . $answersDelay . PHP_EOL);
//            echo("listing_certified:" . $certified . PHP_EOL);
//            echo("listing_new_bonus:" . $listingNewBonus . PHP_EOL);
//            echo("listing_random_bonus:" . $randomBonus . PHP_EOL);
//            echo("notation:" . $notation . PHP_EOL . PHP_EOL);

        }

        $this->em->flush();

        return $nbListings;
    }


    /**
     * Get note from value
     *
     * @param $type
     * @param $val
     * @return int
     */
    private function getNote($type, $val)
    {
        if ($type == "calDays") {
            if ($val <= 7) {
                $val = 5;
            } elseif ($val <= 15) {
                $val = 4;
            } elseif ($val <= 30) {
                $val = 3;
            } elseif ($val <= 45) {
                $val = 2;
            } elseif ($val <= 60) {
                $val = 1;
            } else {
                $val = 0;
            }
        } elseif ($type == "answersDelay") {
            if ($val > 0) {
                if ($val <= 7200) {//2h
                    $val = 5;
                } elseif ($val <= 14400) {//4h
                    $val = 4;
                } elseif ($val <= 43200) {//12h
                    $val = 3;
                } elseif ($val <= 86400) {//24h
                    $val = 2;
                } elseif ($val <= 129600) {//36h
                    $val = 1;
                } else {
                    $val = 0;
                }
            }
        } elseif ($type == "lastBookings") {
            if ($val >= 5) {
                $val = 5;
            } elseif ($val >= 4) {
                $val = 4;
            } elseif ($val >= 3) {
                $val = 3;
            } elseif ($val >= 2) {
                $val = 2;
            } elseif ($val >= 1) {
                $val = 1;
            } else {
                $val = 0;
            }
        } else {
            $val = 0;
        }

        return $val / 5;
    }
}