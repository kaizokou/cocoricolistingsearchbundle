<?php

/*
 * This file is part of the Cocorico package.
 *
 * (c) Cocolabs SAS <contact@cocolabs.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cocorico\ListingSearchBundle\Event;

use Cocorico\CoreBundle\Document\ListingAvailability;
use Cocorico\CoreBundle\Event\ListingSearchEvent;
use Cocorico\CoreBundle\Event\ListingSearchEvents;
use Cocorico\CoreBundle\Model\ListingSearchRequest;
use Cocorico\CoreBundle\Model\Manager\ListingSearchManager;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ListingSearchSubscriber implements EventSubscriberInterface
{
    protected $listingSearchManager;
    protected $locationType;
    protected $distanceViewportDivisions;
    protected $maxDistance;
    protected $distanceViewport;
    protected $listingSearchMinResult;

    /**
     * @param ListingSearchManager $listingSearchManager
     * @param array                $parameters
     */
    public function __construct(ListingSearchManager $listingSearchManager, $parameters)
    {
        $this->listingSearchManager = $listingSearchManager;
        $parameters = $parameters["parameters"];
        $this->locationType = $parameters["cocorico_listing_search_location_type"];
        $this->distanceViewportDivisions = $parameters["cocorico_listing_search_distance_viewport_divisions"];
        $this->maxDistance = $parameters["cocorico_listing_search_max_distance"];
        $this->listingSearchMinResult = $parameters["cocorico_listing_search_min_result"];
    }

    /**
     * Modify search query
     *
     * @param ListingSearchEvent $event
     * @return bool
     * @throws \Exception
     */
    public function onListingSearch(ListingSearchEvent $event)
    {
        $listingSearchRequest = $event->getListingSearchRequest();
        $queryBuilder = $event->getQueryBuilder();

        $queryBuilder->addSelect('partial l.{id, platformNotation}');

        $queryBuilder = $this->getQueryBuilderLocationPart($queryBuilder, $listingSearchRequest);

        //Dates availabilities (from MongoDB)
        $dateRange = $listingSearchRequest->getDateRange();
        if ($dateRange && $dateRange->getStart() && $dateRange->getEnd()) {
            if ($this->listingSearchManager->getListingDefaultStatus() == ListingAvailability::STATUS_AVAILABLE) {
                //Get listings really available for searched dates
                $listingsAvailable = $this->listingSearchManager->getListingsAvailability(
                    $dateRange,
                    $listingSearchRequest->getTimeRange(),
                    $listingSearchRequest->getFlexibility(),
                    null,
                    array(ListingAvailability::STATUS_AVAILABLE)
                );

                //Listings really available
                if (count($listingsAvailable)) {
                    $queryBuilder
                        ->addSelect("CASE WHEN l.id IN (:listingsAvailable) THEN 1 ELSE 0 END as really_available")
                        ->setParameter('listingsAvailable', array_keys($listingsAvailable));
                }
            }
        }

        //Order
        switch ($listingSearchRequest->getSortBy()) {
            case 'price':
                $queryBuilder->orderBy("l.price", "ASC");
                $queryBuilder->addOrderBy("l.platformNotation", "DESC");
                break;
            case 'distance':
                $queryBuilder->orderBy("distance", "ASC");
                $queryBuilder->addOrderBy("l.platformNotation", "DESC");
                break;
            default://recommended
                if ($this->locationType == 'distance') {
                    $queryBuilder->orderBy("distance_range", "ASC");
                } else {
                    $queryBuilder->orderBy("accuracy", "DESC");
                }

                if (isset($listingsAvailable) && count($listingsAvailable)) {
                    $queryBuilder->addOrderBy("really_available", "DESC");
                }
                $queryBuilder->addOrderBy("l.platformNotation", "DESC");
                $queryBuilder->addOrderBy("distance", "ASC");
                break;
        }

        $event->setQueryBuilder($queryBuilder);
//        $event->stopPropagation();
    }

    /**
     * @param QueryBuilder         $queryBuilder
     * @param ListingSearchRequest $listingSearchRequest
     * @param int                  $extensionLevel
     *
     * @return QueryBuilder
     */
    private function getQueryBuilderLocationPart(
        QueryBuilder $queryBuilder,
        ListingSearchRequest $listingSearchRequest,
        $extensionLevel = 1
    ) {
        $searchLocation = $listingSearchRequest->getLocation();

        if ($this->locationType == 'distance') {//search by distance
            $queryBuilderTmp = clone $queryBuilder;

            $queryBuilderTmp = $this->removeWhereClausePart($queryBuilderTmp);

            //Increase search distance if insuficiant result. Min viewport distance is 1km
            $distanceViewport = max($searchLocation->getViewportDiagonalDistance(), 1) * pow(2, $extensionLevel - 1);

            //New location relative where clause is done by distance
            $queryBuilderTmp
                ->andHaving('distance <= :viewportDistance')
                ->setParameter('viewportDistance', $distanceViewport);

            //Split viewport in X parts in which listings are groupe by distance
            $nbDivisions = 1;
            foreach ($this->distanceViewportDivisions as $distanceViewportParam => $nbDivisionsParam) {
                if ($distanceViewport <= $distanceViewportParam) {
                    $nbDivisions = $nbDivisionsParam;
                    break;
                }
            }

            //distance viewport is divided in $nbDivisions
            $distanceRange = round($distanceViewport / $nbDivisions, 4);
            $nbDivisions--;//The last case is greater than all others cases
            if (!$nbDivisions) {//nb division is equal to 0 if distance viewport is too small
                $distanceSQL = '1 AS distance_range';
            } else {
                $distanceSQL = '';
                for ($i = 0; $i < $nbDivisions; $i++) {
                    //todo: don't repeat GEO_DISTANCE here
                    $distanceSQL .= (!$i ? "CASE " : "") . "WHEN GEO_DISTANCE(co.lat = :lat, co.lng = :lng) <= :viewportDistance$i THEN $i " .
                        ($i == ($nbDivisions - 1) ? "ELSE $nbDivisions END AS distance_range " : "");
                    $queryBuilderTmp
                        ->setParameter("viewportDistance$i", ($i + 1) * $distanceRange);
                }
            }

            $queryBuilderTmp
                ->addSelect($distanceSQL);

            //Listings locations: The search location distance is extended while number of results is insuficiant
            if (count($queryBuilderTmp->getQuery()->getArrayResult()) < $this->listingSearchMinResult &&
                $distanceViewport < $this->maxDistance
            ) {
                $queryBuilderTmp = $this->getQueryBuilderLocationPart(
                    $queryBuilder,
                    $listingSearchRequest,
                    $extensionLevel + 1
                );
            }
            $queryBuilder = $queryBuilderTmp;
        } else {//search by viewport and location accuracy
            //Translated location ordering :
            //Listings having a location the most accurate compared to the searched one will be shown at first
            $accuracyMethod = $searchLocation->getAccuracyMethod();
            $accuracyMapping = $searchLocation->getAccuracyMapping();
            if ($accuracyMethod) {
                $accuracy = $searchLocation->$accuracyMethod();
                if ($accuracyMapping['table']) {
                    $queryBuilder
                        ->addSelect(
                            "CASE WHEN LOWER(amt." . $accuracyMapping['field'] . ") = :accuracy THEN 1 ELSE 0 END as accuracy"
                        )
                        ->leftJoin('co.' . $accuracyMapping['table'], 'am')
                        ->leftJoin('am.translations', 'amt')
                        ->andWhere('amt.locale = :locale');
                } else {
                    $queryBuilder->addSelect(
                        "CASE WHEN co." . $accuracyMapping['field'] . " = :accuracy THEN 1 ELSE 0 END as accuracy"
                    );
                }
                $queryBuilder->setParameter('accuracy', strtolower($accuracy));
            }
        }

        return $queryBuilder;
    }

    /**
     * Remove some part of queryBuilder where clause
     *
     * @param QueryBuilder $queryBuilder
     *
     * @return QueryBuilder
     */
    private function removeWhereClausePart(QueryBuilder $queryBuilder)
    {
        //See ssrc/Cocorico/CoreBundle/Model/Manager/ListingSearchManager.php:91
        $whereClausesToRemove = array('co.lat < :neLat', 'co.lat > :swLat', 'co.lng < :neLng', 'co.lng > :swLng');
        $whereParamsToRemove = array('neLat', 'swLat', 'neLng', 'swLng');

        //Remove location relative where clauses
        $whereParts = $queryBuilder->getDqlPart('where')->getParts();
        $queryBuilder->resetDQLPart('where');
        foreach ($whereParts as $wherePart) {
            if (in_array($wherePart, $whereClausesToRemove)) {
                continue;
            }
            $queryBuilder->andWhere($wherePart);
        }

        //Remove location relative where clauses parameters
        $parameters = $queryBuilder->getParameters();
        foreach ($parameters as $key => $parameter) {
            if (in_array($parameter->getName(), $whereParamsToRemove)) {
                $parameters->remove($key);
            }
        }
        $queryBuilder->setParameters($parameters);

        return $queryBuilder;
    }

    public static function getSubscribedEvents()
    {
        return array(
            ListingSearchEvents::LISTING_SEARCH => array('onListingSearch', 1),
        );
    }

}