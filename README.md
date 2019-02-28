Cocorico Listing Search Bundle
==============================

# Installation

## Edit `composer.json`:
     
    ...
    "repositories": [
        {
          "type": "composer",
          "url": "https://packages.cocorico.io",
          "options": {
            "ssl": {
              "verify_peer": true,
              "allow_self_signed": true
            }
          }
        }
    ],
    ...

## Copy / paste auth.json.dist to auth.json and add Cocorico account in auth.json in "http-basic" part.

Use 0.1 version for Cocorico 0.1 branch and 0.2 for Cocorico 0.2 branch

    php composer.phar require cocorico/listing-search-bundle "^0.2"
    
    
## Edit `app/config/AppKernel.php` file:

    public function registerBundles()
    {
        $bundles = array(
            // ...
            new Cocorico\MangoPayBundle\CocoricoListingSearchBundle(),
            // ...
        );

        return $bundles;
    }

## Update `Cocorico/CoreBundle/Entity/Listing.php` file:

    ...
    @ORM\Table(name="listing",indexes={
    ...
    *   @ORM\Index(name="platform_notation_idx", columns={"platform_notation"}),
    ...
     
    class Listing extends BaseListing
    {
        ...
        use \Cocorico\ListingSearchBundle\Model\ListingSearchableTrait;
        ...
        

        
## Update schema:

Dry run:
    
   `php app/console doctrine:schema:update --dump-sql`
        
Update: 
    
   `php app/console doctrine:schema:update --force --env=dev`
        
        
## Add cron:

Listings platform notation computing:
        
    `30 2 * * * php <path-to-your-app>app/console cocorico_listing_search:computeNotation --env=dev`
    
        
## Optionally Edit `app/config/parameters.yml` file to adjust notation weight:
    ...
    cocorico_listing_search.notation_weights:
       listing_admin_notation: 5
       listing_fill_rate: 4
       listing_avg_rating: 5
       listing_availabilities_updated: 4
       listing_last_bookings: 4
       listing_certified: 4
       listing_new_bonus: 3
       listing_random_bonus: 2
       offerer_fill_rate: 3
       offerer_answers_rate: 4
       offerer_acceptation_rate: 4
       offerer_nb_bank_wires: 4
       offerer_answers_delay: 3
    ...        
            